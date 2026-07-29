// resources/js/tailwind.config.js
import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: ['class', '[data-mode="dark"]'],
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                primary: {
                    DEFAULT: '#3e60d5',
                    600: '#324fb6',
                },
                secondary: {
                    DEFAULT: '#6c757d',
                },
                success: {
                    DEFAULT: '#47ad77',
                },
                info: {
                    DEFAULT: '#16a7e9',
                },
                warning: {
                    DEFAULT: '#ffc35a',
                },
                danger: {
                    DEFAULT: '#f15776',
                },
                light: {
                    DEFAULT: '#f2f2f7',
                },
                dark: {
                    DEFAULT: '#212529',
                },
            }
        },
    },

    plugins: [forms],
};