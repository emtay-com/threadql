import { jwtDecode } from 'jwt-decode';

/**
 * Decode JWT token payload
 * @param {string} token - JWT token string
 * @returns {object|null} - Decoded payload or null if invalid
 */
const decodeToken = (token) => {
    if (!token) {
        return null;
    }

    try {
        return jwtDecode(token)
    } catch {
        console.error('Failed to decode JWT token');
        return null;
    }
}

const isValidToken = (token) => {
    const decodedToken = decodeToken(token);

    if (!decodedToken) {
        return false;
    }

    const exp = decodedToken.exp;

    if (!exp || exp < Date.now() / 1000) {
        return false;
    }

    return true;
}


export {
    isValidToken,
    decodeToken
}
