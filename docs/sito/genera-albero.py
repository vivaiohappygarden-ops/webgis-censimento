"""Generatore dell'albero della tavola delle quote (resources/views/sito/parti/quote.blade.php).

Uso:  python3 docs/sito/genera-albero.py 0.70 0.75 > /tmp/albero.svg
      (primo numero: scala dei rami; secondo: apertura delle branche)
L'ultima riga stampata dice gli estremi della chioma (sinistra, destra, cima):
vanno riportati nelle linee di quota della vista. Il frammento si incolla al
posto del blocco "albero: scheletro generato". Seme fisso: stesso disegno a
ogni lancio, cosi' il file nel repository e' riproducibile.
"""
import math, random, sys
SCALA = float(sys.argv[1]) if len(sys.argv) > 1 else 0.68
APERTURA = float(sys.argv[2]) if len(sys.argv) > 2 else 0.8
random.seed(20260919)

def bez(p0, p1, p2, p3, t):
    u = 1 - t
    return (u*u*u*p0[0] + 3*u*u*t*p1[0] + 3*u*t*t*p2[0] + t*t*t*p3[0],
            u*u*u*p0[1] + 3*u*u*t*p1[1] + 3*u*t*t*p2[1] + t*t*t*p3[1])

def bez_d(p0, p1, p2, p3, t):
    u = 1 - t
    return (3*u*u*(p1[0]-p0[0]) + 6*u*t*(p2[0]-p1[0]) + 3*t*t*(p3[0]-p2[0]),
            3*u*u*(p1[1]-p0[1]) + 6*u*t*(p2[1]-p1[1]) + 3*t*t*(p3[1]-p2[1]))

def ramo_path(p0, p1, p2, p3, w0, w1, n=14):
    """Sagoma piena di un ramo rastremato lungo una cubica."""
    sx, dx = [], []
    for i in range(n + 1):
        t = i / n
        x, y = bez(p0, p1, p2, p3, t)
        tx, ty = bez_d(p0, p1, p2, p3, t)
        l = math.hypot(tx, ty) or 1
        nx, ny = -ty / l, tx / l
        w = (w0 + (w1 - w0) * t) / 2
        sx.append((x + nx * w, y + ny * w)); dx.append((x - nx * w, y - ny * w))
    pts = sx + dx[::-1]
    return 'M' + ' '.join(f'{x:.1f} {y:.1f}' for x, y in pts) + 'Z'

rami = []      # (livello, path)
punte = []     # estremi dei rami terminali, per la chioma

def cresci(base, angolo, lunghezza, w0, w1, livello, curva=None):
    """Un ramo dalla base con direzione 'angolo' (gradi, 0 = su), poi i figli."""
    a = math.radians(angolo)
    piega = curva if curva is not None else random.uniform(-14, 14)
    a2 = math.radians(angolo + piega)
    p3 = (base[0] + math.sin(a2) * lunghezza, base[1] - math.cos(a2) * lunghezza)
    p1 = (base[0] + math.sin(a) * lunghezza * 0.38, base[1] - math.cos(a) * lunghezza * 0.38)
    p2 = (p3[0] - math.sin(a2) * lunghezza * 0.32, p3[1] + math.cos(a2) * lunghezza * 0.32)
    rami.append((livello, ramo_path(base, p1, p2, p3, w0, w1)))
    if livello >= 3:
        punte.append(p3)
    if livello >= 4:
        return
    figli = 3 if livello <= 2 else 2
    for k in range(figli):
        # i figli partono lungo il ramo (non tutti in punta) con angoli aperti
        t = random.uniform(0.55, 0.95) if k < figli - 1 else 1.0
        attacco = bez(base, p1, p2, p3, t)
        lato = -1 if k % 2 == 0 else 1
        dev = lato * random.uniform(22, 40) * APERTURA if t < 1 else random.uniform(-10, 10)
        nuovo_ang = angolo + piega * t + dev
        # la chioma e' piu' larga che alta: i rami laterali si aprono, quelli in cima meno
        nuova_l = lunghezza * random.uniform(0.58, 0.72)
        cresci(attacco, nuovo_ang, nuova_l, w1 * 1.05 if t == 1 else w1 * 0.9, max(w1 * 0.45, 1.2), livello + 1)

# Tronco: dal colletto (y=430) al punto di inserzione delle branche (y~300)
tronco = ramo_path((240, 430), (238, 392), (241, 340), (240, 300), 34, 24, 10)
# colletto: due curve di raccordo al terreno
colletto = 'M196 430C214 428 222 418 224 404L224 430ZM284 430C266 428 258 418 256 404L256 430Z'
# Tre branche principali dall'inserzione, con angoli aperti
cresci((236, 304), -30 * APERTURA - 4, 118 * SCALA, 15, 8, 2, curva=-8)
cresci((241, 300), 3, 138 * SCALA, 14, 7, 2, curva=4)
cresci((245, 304), 28 * APERTURA + 4, 112 * SCALA, 15, 8, 2, curva=10)

# Chioma: raggio per settore angolare attorno al centro delle punte, piu' un margine
cx = sum(p[0] for p in punte) / len(punte); cy = sum(p[1] for p in punte) / len(punte)
settori = 28
raggi = []
for i in range(settori):
    ang = -math.pi + (i + 0.5) * 2 * math.pi / settori
    r = 0
    for (x, y) in punte:
        a = math.atan2(y - cy, x - cx)
        d = abs((a - ang + math.pi) % (2 * math.pi) - math.pi)
        if d < math.radians(50):
            r = max(r, math.hypot(x - cx, y - cy) * math.cos(d))
    raggi.append(r)
# una chioma piena: nessun settore scende sotto il 78% del raggio massimo
pavimento = max(raggi) * 0.78
raggi = [max(r, pavimento) for r in raggi]
# leviga due volte, poi margine e una leggera irregolarita'
for _ in range(2):
    raggi = [(raggi[i - 1] + 2 * raggi[i] + raggi[(i + 1) % settori]) / 4 for i in range(settori)]
lisci = [raggi[i] + 24 + random.uniform(-3, 4) for i in range(settori)]
punti = []
for i in range(settori):
    ang = -math.pi + (i + 0.5) * 2 * math.pi / settori
    punti.append((cx + math.cos(ang) * lisci[i], cy + math.sin(ang) * lisci[i]))
# la base della chioma non scende sotto l'inserzione delle branche piu' di tanto
punti = [(x, min(y, 318)) for x, y in punti]
# curva chiusa Catmull-Rom -> cubiche
def catmull(pts):
    n = len(pts); out = f'M{pts[0][0]:.1f} {pts[0][1]:.1f}'
    for i in range(n):
        p0, p1, p2, p3 = pts[i - 1], pts[i], pts[(i + 1) % n], pts[(i + 2) % n]
        c1 = (p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6)
        c2 = (p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6)
        out += f'C{c1[0]:.1f} {c1[1]:.1f} {c2[0]:.1f} {c2[1]:.1f} {p2[0]:.1f} {p2[1]:.1f}'
    return out + 'Z'
chioma = catmull(punti)
sinistra = min(x for x, y in punti); destra = max(x for x, y in punti); cima = min(y for x, y in punti)

# Fogliame: ciuffi di tre archetti vicino alle punte, orientati a caso
ciuffi = []
for (x, y) in punte:
    for _ in range(7):
        a = random.uniform(0, 2 * math.pi); d = random.uniform(4, 16)
        ciuffi.append(f'M{x + math.cos(a) * d:.1f} {y + math.sin(a) * d:.1f}h0.1')

svg = []
svg.append(f'<path d="{chioma}" fill="#d9e5d6" stroke="#476f52" stroke-width="1.4" stroke-linejoin="round" />')
svg.append('<g fill="none" stroke="#476f52" stroke-width="2.6" stroke-opacity="0.45" stroke-linecap="round"><path d="' + ''.join(ciuffi) + '" /></g>')
svg.append('<g fill="#12382a">')
svg.append(f'<path d="{tronco}" />')
svg.append(f'<path d="{colletto}" />')
for livello, d in sorted(rami, key=lambda r: r[0]):
    svg.append(f'<path d="{d}" />')
svg.append('</g>')
print('\n'.join(svg))
print(f'<!-- estremi chioma: sinistra {sinistra:.0f} destra {destra:.0f} cima {cima:.0f}; punte {len(punte)} -->')
