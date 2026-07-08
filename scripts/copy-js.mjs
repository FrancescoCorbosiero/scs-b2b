// Copia i bundle JS in public/assets (nessun CDN in produzione).
import { copyFileSync, mkdirSync } from 'node:fs';

mkdirSync('public/assets', { recursive: true });
copyFileSync('node_modules/alpinejs/dist/cdn.min.js', 'public/assets/alpine.min.js');
copyFileSync('assets/js/app.js', 'public/assets/app.js');
console.log('JS copiati in public/assets/');
