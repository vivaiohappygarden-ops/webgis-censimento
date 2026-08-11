/**
 * Compressione client-side delle foto di campo (OFFLINE-SYNC §7.1):
 * lato lungo max 1600 px, JPEG qualità 0.8 — da ~4-8 MB a ~200-500 KB,
 * dimensione adatta alla coda offline e alle reti di campagna.
 */
export async function compressImage(file, maxSide = 1600, quality = 0.8) {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
    const width = Math.round(bitmap.width * scale);
    const height = Math.round(bitmap.height * scale);

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height);
    bitmap.close?.();

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    if (! blob) throw new Error('Compressione della foto non riuscita.');
    return blob;
}
