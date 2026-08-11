/**
 * UUID v7 (RFC 9562): prefisso temporale a 48 bit + 74 bit casuali.
 * Gli ID creati offline sono definitivi (OFFLINE-SYNC §1.2): l'ordinabilità
 * temporale riduce i page split negli indici B-tree del server.
 */
export function uuidv7() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    const ts = BigInt(Date.now());
    bytes[0] = Number((ts >> 40n) & 0xffn);
    bytes[1] = Number((ts >> 32n) & 0xffn);
    bytes[2] = Number((ts >> 24n) & 0xffn);
    bytes[3] = Number((ts >> 16n) & 0xffn);
    bytes[4] = Number((ts >> 8n) & 0xffn);
    bytes[5] = Number(ts & 0xffn);

    bytes[6] = (bytes[6] & 0x0f) | 0x70; // versione 7
    bytes[8] = (bytes[8] & 0x3f) | 0x80; // variante RFC

    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
