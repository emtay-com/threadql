"""
SSH Tunnel Manager Sidecar

Maintains persistent SSH port-forwarding tunnels for database connections.
Tunnels are identified by datasource_id and allocated from a configurable
port pool (default 13300–13399). State is persisted in Redis so multiple
PHP workers can coordinate without race conditions.
"""

import asyncio
import os
import time
import logging
from contextlib import asynccontextmanager
from typing import Optional

import asyncssh
import redis.asyncio as aioredis
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# --- Configuration -----------------------------------------------------------

REDIS_URL = os.getenv("REDIS_URL", "redis://localhost:6379")
PORT_RANGE_START = int(os.getenv("PORT_RANGE_START", "13300"))
PORT_RANGE_END = int(os.getenv("PORT_RANGE_END", "13399"))

TUNNEL_TTL = 2700        # seconds — Redis TTL for port assignment
IDLE_TIMEOUT = 1800      # seconds — close tunnels idle longer than this
CLEANUP_INTERVAL = 60    # seconds — how often the background cleanup runs

SSH_KEEPALIVE_INTERVAL = 60
SSH_KEEPALIVE_COUNT_MAX = 10

# --- In-memory connection store (keyed by datasource_id) ---------------------
# Each entry: {"conn": asyncssh.SSHClientConnection, "listener": asyncssh.SSHListener}
_connections: dict[str, dict] = {}

# --- Redis client (module-level, initialised in lifespan) --------------------
_redis: Optional[aioredis.Redis] = None


# --- Helper: Redis key names -------------------------------------------------

def _key_port(datasource_id: str) -> str:
    return f"ssh:tunnel:port:{datasource_id}"


def _key_used_ports() -> str:
    return "ssh:tunnel:ports:used"


def _key_activity(datasource_id: str) -> str:
    return f"ssh:tunnel:last_activity:{datasource_id}"


# --- Port allocation ---------------------------------------------------------

async def _allocate_port() -> Optional[int]:
    """Claim the lowest free port from the pool; return None if full."""
    for port in range(PORT_RANGE_START, PORT_RANGE_END + 1):
        added = await _redis.sadd(_key_used_ports(), port)
        if added:
            return port
    return None


async def _release_port(port: int) -> None:
    await _redis.srem(_key_used_ports(), port)


# --- Core tunnel operations --------------------------------------------------

async def _open_tunnel(datasource_id: str, request: "TunnelRequest") -> int:
    """Open a new SSH tunnel; returns the allocated local port."""
    port = await _allocate_port()
    if port is None:
        raise RuntimeError("No free ports available in tunnel pool")

    try:
        connect_kwargs: dict = dict(
            host=request.ssh_host,
            port=request.ssh_port,
            username=request.ssh_username,
            known_hosts=None,
            keepalive_interval=SSH_KEEPALIVE_INTERVAL,
            keepalive_count_max=SSH_KEEPALIVE_COUNT_MAX,
        )
        if request.ssh_private_key:
            connect_kwargs["client_keys"] = [
                asyncssh.import_private_key(request.ssh_private_key)
            ]
        elif request.ssh_password:
            connect_kwargs["password"] = request.ssh_password
        else:
            raise ValueError("Either ssh_private_key or ssh_password must be provided")

        conn = await asyncssh.connect(**connect_kwargs)
        listener = await conn.forward_local_port(
            "0.0.0.0", port, request.remote_host, request.remote_port
        )

        _connections[datasource_id] = {"conn": conn, "listener": listener, "port": port}

        await _redis.set(_key_port(datasource_id), port, ex=TUNNEL_TTL)
        await _redis.set(_key_activity(datasource_id), time.time(), ex=TUNNEL_TTL)

        logger.info(
            "Opened tunnel ds=%s local_port=%d -> %s:%d via %s",
            datasource_id, port, request.remote_host, request.remote_port, request.ssh_host,
        )
        return port

    except Exception:
        await _release_port(port)
        raise


async def _close_tunnel(datasource_id: str) -> None:
    """Close the SSH connection and release the port for datasource_id."""
    entry = _connections.pop(datasource_id, None)
    if entry:
        try:
            entry["listener"].close()
            entry["conn"].close()
        except Exception as exc:
            logger.warning("Error closing tunnel ds=%s: %s", datasource_id, exc)
        await _release_port(entry["port"])

    await _redis.delete(_key_port(datasource_id))
    await _redis.delete(_key_activity(datasource_id))
    logger.info("Closed tunnel ds=%s", datasource_id)


def _is_connection_alive(datasource_id: str) -> bool:
    entry = _connections.get(datasource_id)
    if not entry:
        return False
    conn: asyncssh.SSHClientConnection = entry["conn"]
    return not conn.is_closed()


# --- Background cleanup task -------------------------------------------------

async def _cleanup_loop() -> None:
    while True:
        await asyncio.sleep(CLEANUP_INTERVAL)
        try:
            await _run_cleanup()
        except Exception as exc:
            logger.error("Cleanup error: %s", exc)


async def _run_cleanup() -> None:
    now = time.time()
    for datasource_id in list(_connections.keys()):
        raw = await _redis.get(_key_activity(datasource_id))
        if raw is None:
            logger.info("Cleanup: no activity record, closing ds=%s", datasource_id)
            await _close_tunnel(datasource_id)
            continue
        last_activity = float(raw)
        if now - last_activity > IDLE_TIMEOUT:
            logger.info(
                "Cleanup: idle timeout (%.0fs), closing ds=%s",
                now - last_activity, datasource_id,
            )
            await _close_tunnel(datasource_id)


# --- Lifespan ----------------------------------------------------------------

@asynccontextmanager
async def lifespan(application: FastAPI):
    global _redis
    _redis = aioredis.from_url(REDIS_URL, decode_responses=True)
    task = asyncio.create_task(_cleanup_loop())
    logger.info(
        "SSH tunnel manager started. Port pool %d–%d",
        PORT_RANGE_START, PORT_RANGE_END,
    )
    yield
    task.cancel()
    # Close all open tunnels on shutdown
    for ds_id in list(_connections.keys()):
        await _close_tunnel(ds_id)
    await _redis.aclose()


# --- FastAPI application ------------------------------------------------------

app = FastAPI(title="SSH Tunnel Manager", lifespan=lifespan)


# --- Request / Response models -----------------------------------------------

class TunnelRequest(BaseModel):
    datasource_id: str
    ssh_host: str
    ssh_port: int = 22
    ssh_username: str
    ssh_private_key: Optional[str] = None
    ssh_password: Optional[str] = None
    remote_host: str
    remote_port: int


class TunnelResponse(BaseModel):
    datasource_id: str
    local_port: int
    status: str  # "created" | "reused"


class TunnelInfo(BaseModel):
    datasource_id: str
    local_port: int
    alive: bool
    last_activity: Optional[float]


class HealthResponse(BaseModel):
    status: str
    active_count: int


# --- Endpoints ----------------------------------------------------------------

@app.post("/tunnels", response_model=TunnelResponse)
async def create_or_reuse_tunnel(request: TunnelRequest) -> TunnelResponse:
    ds_id = request.datasource_id

    # Check Redis for an existing port assignment
    existing_port_raw = await _redis.get(_key_port(ds_id))
    if existing_port_raw is not None:
        existing_port = int(existing_port_raw)
        if _is_connection_alive(ds_id):
            # Refresh TTL and activity timestamp
            await _redis.expire(_key_port(ds_id), TUNNEL_TTL)
            await _redis.set(_key_activity(ds_id), time.time(), ex=TUNNEL_TTL)
            logger.info("Reusing tunnel ds=%s port=%d", ds_id, existing_port)
            return TunnelResponse(
                datasource_id=ds_id, local_port=existing_port, status="reused"
            )
        # Port exists in Redis but connection is dead — clean up and recreate
        logger.info("Dead tunnel detected for ds=%s, recreating", ds_id)
        await _close_tunnel(ds_id)

    try:
        port = await _open_tunnel(ds_id, request)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except RuntimeError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc
    except Exception as exc:
        logger.error("Failed to open tunnel for ds=%s: %s", ds_id, exc)
        raise HTTPException(
            status_code=502, detail=f"SSH connection failed: {exc}"
        ) from exc

    return TunnelResponse(datasource_id=ds_id, local_port=port, status="created")


@app.get("/tunnels", response_model=list[TunnelInfo])
async def list_tunnels() -> list[TunnelInfo]:
    result = []
    for ds_id, entry in _connections.items():
        raw = await _redis.get(_key_activity(ds_id))
        result.append(
            TunnelInfo(
                datasource_id=ds_id,
                local_port=entry["port"],
                alive=_is_connection_alive(ds_id),
                last_activity=float(raw) if raw else None,
            )
        )
    return result


@app.get("/tunnels/{datasource_id}", response_model=TunnelInfo)
async def get_tunnel(datasource_id: str) -> TunnelInfo:
    entry = _connections.get(datasource_id)
    if not entry:
        raise HTTPException(status_code=404, detail="Tunnel not found")
    raw = await _redis.get(_key_activity(datasource_id))
    return TunnelInfo(
        datasource_id=datasource_id,
        local_port=entry["port"],
        alive=_is_connection_alive(datasource_id),
        last_activity=float(raw) if raw else None,
    )


@app.delete("/tunnels/{datasource_id}", status_code=204)
async def delete_tunnel(datasource_id: str) -> None:
    if datasource_id not in _connections:
        raise HTTPException(status_code=404, detail="Tunnel not found")
    await _close_tunnel(datasource_id)


@app.get("/health", response_model=HealthResponse)
async def health() -> HealthResponse:
    return HealthResponse(status="ok", active_count=len(_connections))
