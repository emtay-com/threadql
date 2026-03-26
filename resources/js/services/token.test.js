import { describe, it, expect } from 'vitest';
import { decodeToken, isValidToken } from './token.js';

function makeJwt(payload) {
    const header = btoa(JSON.stringify({ alg: 'HS256', typ: 'JWT' }))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    const body = btoa(JSON.stringify(payload))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    return `${header}.${body}.fakesig`;
}

const VALID_TOKEN = makeJwt({ sub: '1', exp: 9999999999 });
const EXPIRED_TOKEN = makeJwt({ sub: '1', exp: 1 });

describe('decodeToken', () => {
    it('returns null for null input', () => {
        expect(decodeToken(null)).toBeNull();
    });

    it('returns null for an invalid token string', () => {
        expect(decodeToken('invalid')).toBeNull();
    });

    it('returns the decoded payload for a valid JWT', () => {
        const decoded = decodeToken(VALID_TOKEN);
        expect(decoded).not.toBeNull();
        expect(decoded.sub).toBe('1');
        expect(decoded.exp).toBe(9999999999);
    });
});

describe('isValidToken', () => {
    it('returns false for null', () => {
        expect(isValidToken(null)).toBe(false);
    });

    it('returns false for an expired token', () => {
        expect(isValidToken(EXPIRED_TOKEN)).toBe(false);
    });

    it('returns true for a valid non-expired token', () => {
        expect(isValidToken(VALID_TOKEN)).toBe(true);
    });
});
