// Copia i bundle JS e i font in public/assets (nessun CDN in produzione:
// la CSP ammette solo 'self' per script, stili e font).
import { copyFileSync, mkdirSync } from 'node:fs';

mkdirSync('public/assets/fonts', { recursive: true });
copyFileSync('node_modules/alpinejs/dist/cdn.min.js', 'public/assets/alpine.min.js');
copyFileSync('assets/js/app.js', 'public/assets/app.js');

// font variabili self-hosted (latin + latin-ext: copre IT/EN e i nomi dei paesi UE)
const fonts = {
    '@fontsource-variable/inter/files': ['inter-latin-wght-normal.woff2', 'inter-latin-ext-wght-normal.woff2'],
    '@fontsource-variable/archivo/files': ['archivo-latin-wdth-normal.woff2', 'archivo-latin-ext-wdth-normal.woff2'],
};
for (const [dir, files] of Object.entries(fonts)) {
    for (const file of files) {
        copyFileSync(`node_modules/${dir}/${file}`, `public/assets/fonts/${file}`);
    }
}
console.log('JS e font copiati in public/assets/');
