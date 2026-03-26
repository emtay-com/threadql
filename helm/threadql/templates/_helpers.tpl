{{/*
Expand the name of the chart.
*/}}
{{- define "threadql.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a default fully qualified app name.
*/}}
{{- define "threadql.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Create chart name and version as used by the chart label.
*/}}
{{- define "threadql.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels
*/}}
{{- define "threadql.labels" -}}
helm.sh/chart: {{ include "threadql.chart" . }}
{{ include "threadql.selectorLabels" . }}
{{- if .Chart.AppVersion }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Selector labels
*/}}
{{- define "threadql.selectorLabels" -}}
app.kubernetes.io/name: {{ include "threadql.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
App component labels
*/}}
{{- define "threadql.app.labels" -}}
{{ include "threadql.labels" . }}
app.kubernetes.io/component: app
{{- end }}

{{- define "threadql.app.selectorLabels" -}}
{{ include "threadql.selectorLabels" . }}
app.kubernetes.io/component: app
{{- end }}

{{/*
Worker component labels
*/}}
{{- define "threadql.worker.labels" -}}
{{ include "threadql.labels" . }}
app.kubernetes.io/component: worker
{{- end }}

{{- define "threadql.worker.selectorLabels" -}}
{{ include "threadql.selectorLabels" . }}
app.kubernetes.io/component: worker
{{- end }}

{{/*
SSH Tunnel component labels
*/}}
{{- define "threadql.sshTunnel.labels" -}}
{{ include "threadql.labels" . }}
app.kubernetes.io/component: ssh-tunnel
{{- end }}

{{- define "threadql.sshTunnel.selectorLabels" -}}
{{ include "threadql.selectorLabels" . }}
app.kubernetes.io/component: ssh-tunnel
{{- end }}

{{/*
Redis component labels
*/}}
{{- define "threadql.redis.labels" -}}
{{ include "threadql.labels" . }}
app.kubernetes.io/component: redis
{{- end }}

{{- define "threadql.redis.selectorLabels" -}}
{{ include "threadql.selectorLabels" . }}
app.kubernetes.io/component: redis
{{- end }}

{{/*
MySQL component labels
*/}}
{{- define "threadql.mysql.labels" -}}
{{ include "threadql.labels" . }}
app.kubernetes.io/component: mysql
{{- end }}

{{- define "threadql.mysql.selectorLabels" -}}
{{ include "threadql.selectorLabels" . }}
app.kubernetes.io/component: mysql
{{- end }}

{{/*
Resolve the image tag for ThreadQL images.
Per-image tag takes precedence; falls back to global .Values.version.
Usage: {{ include "threadql.imageTag" (dict "imageConfig" .Values.app.php.image "global" .Values) }}
*/}}
{{- define "threadql.imageTag" -}}
{{- .imageConfig.tag | default .global.version | default "latest" }}
{{- end }}

{{/*
Name of the env Secret created by this chart.
*/}}
{{- define "threadql.envSecretName" -}}
{{ include "threadql.fullname" . }}-env
{{- end }}

{{/*
Resolve the env source: either an existing secretRef/configMapRef, or the chart-created secret.
*/}}
{{- define "threadql.envFrom" -}}
{{- if .Values.envFrom.secretRef }}
- secretRef:
    name: {{ .Values.envFrom.secretRef }}
{{- end }}
{{- if .Values.envFrom.configMapRef }}
- configMapRef:
    name: {{ .Values.envFrom.configMapRef }}
{{- end }}
{{- if and (not .Values.envFrom.secretRef) (not .Values.envFrom.configMapRef) }}
- secretRef:
    name: {{ include "threadql.envSecretName" . }}
{{- end }}
{{- end }}

{{/*
Redis host — auto-resolves to in-cluster service if redis.enabled
*/}}
{{- define "threadql.redisHost" -}}
{{- if .Values.redis.enabled }}
{{- printf "%s-redis" (include "threadql.fullname" .) }}
{{- else }}
{{- .Values.env.REDIS_HOST | default "localhost" }}
{{- end }}
{{- end }}

{{/*
MySQL host — auto-resolves to in-cluster service if mysql.enabled
*/}}
{{- define "threadql.mysqlHost" -}}
{{- if .Values.mysql.enabled }}
{{- printf "%s-mysql" (include "threadql.fullname" .) }}
{{- else }}
{{- .Values.env.DB_HOST | default "localhost" }}
{{- end }}
{{- end }}

{{/*
SSH Tunnel manager internal URL
*/}}
{{- define "threadql.sshTunnelUrl" -}}
{{- if .Values.sshTunnel.enabled }}
{{- printf "http://%s-ssh-tunnel:%d" (include "threadql.fullname" .) (int .Values.sshTunnel.service.apiPort) }}
{{- else }}
{{- .Values.env.SSH_TUNNEL_MANAGER_URL | default "" }}
{{- end }}
{{- end }}

{{/*
MCP URL — points to the app service internally
*/}}
{{- define "threadql.mcpUrl" -}}
{{- printf "http://%s:%d/mcp" (include "threadql.fullname" . ) (int .Values.app.service.port) }}
{{- end }}
