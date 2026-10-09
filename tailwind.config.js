/** @type {import('tailwindcss').Config} */
const defaultTheme = require('tailwindcss/defaultTheme');

module.exports = {
    content: [
        './templates/**/*.twig',
        './assets/js/**/*.js',
    ],
    theme: {
        extend: {
            fontFamily: {
                // font self-hosted (scripts/copy-js.mjs → public/assets/fonts)
                sans: ['"Inter Variable"', ...defaultTheme.fontFamily.sans],
                // titoli delle pagine pubbliche: Archivo largo (asse wdth)
                display: ['"Archivo Variable"', '"Inter Variable"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // nero "inchiostro" delle sezioni scure del sito pubblico
                ink: {
                    DEFAULT: '#0b0b0c',
                    900: '#111113',
                    800: '#18181b',
                    700: '#232327',
                },
                // carta: fondo caldo delle sezioni chiare
                paper: '#f6f5f1',
            },
        },
    },
    plugins: [
        require('@tailwindcss/forms'),
    ],
};
