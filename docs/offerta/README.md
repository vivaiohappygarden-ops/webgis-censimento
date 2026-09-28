# Offerta commerciale di ArborLab

`offerta-arborlab.docx` è l'offerta di DAMA S.R.L. per il programma ArborLab, in
Word, da compilare per ogni cliente. I campi da riempire sono fra parentesi
quadre ed evidenziati in giallo (destinatario, numero e data, utenti, importi,
ore di formazione, orari e tempi di assistenza, pagamento, firmatario): in Word
si trovano con Trova "[" e, compilati, si toglie l'evidenziatura.

Parla a Comuni e agronomi: descrive le funzioni operative e gestionali, non
quelle interne, e non cita la console della piattaforma né il collegamento al
gestionale del vivaio.

Per rigenerare il modello vuoto dopo una modifica al testo (non serve per
compilarlo): il testo sta in `genera-docx.cjs`, che usa il pacchetto npm `docx`
(non è fra le dipendenze del progetto).

```bash
npm install --prefix /tmp/docxlib docx@9
NODE_PATH=/tmp/docxlib/node_modules node docs/offerta/genera-docx.cjs
```
