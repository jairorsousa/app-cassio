import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: ['class', '[data-theme="dark"]'],
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/Livewire/**/*.php',
        './app/Domains/**/*.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['"Reddit Sans"', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'sans-serif'],
            },
            colors: {
                primary: {
                    100: '#fff8e1',
                    500: '#f5b800',
                    600: '#d99e00',
                },
                mono: {
                    white: '#ffffff',
                    black: '#212427',
                    50:  '#f5f6f7',
                    100: '#ecedef',
                    200: '#d5d7da',
                    300: '#b2b7bb',
                    600: '#8d959d',
                    900: '#212529',
                },
                system: {
                    up:           '#15a96f',
                    down:         '#e43b3b',
                    success:      '#1cc97d',
                    'success-bg': '#e8f3ea',
                    error:        '#ff4747',
                    'error-bg':   '#fdeaea',
                    info:         '#1a73e8',
                    'info-bg':    '#e8f0fe',
                },
            },
            spacing: {
                xxxs: '0.25rem',
                xxs:  '0.5rem',
                xs:   '0.75rem',
                sm:   '1rem',
                md:   '1.25rem',
                lg:   '1.5rem',
                xl:   '2rem',
                xxl:  '2.5rem',
            },
            borderRadius: {
                xs:  '4px',
                sm:  '8px',
                md:  '12px',
                lg:  '16px',
                xl:  '20px',
                pill: '999px',
            },
            boxShadow: {
                card:     '0 2px 8px rgba(0,0,0,.06)',
                elevated: '0 8px 32px rgba(0,0,0,.12)',
                dropdown: '0 4px 20px 0 hsla(0,0%,54%,.16), 0 4px 20px 0 rgba(0,0,0,.1)',
            },
            fontSize: {
                xxs: ['0.625rem', { lineHeight: '1.2' }],
                xs:  ['0.875rem', { lineHeight: '1.4' }],
                sm:  ['1rem',     { lineHeight: '1.4' }],
                md:  ['1.125rem', { lineHeight: '1.4' }],
                lg:  ['1.25rem',  { lineHeight: '1.4' }],
                xl:  ['1.5rem',   { lineHeight: '1.2' }],
                xxl: ['2rem',     { lineHeight: '1.2' }],
            },
        },
    },
    plugins: [forms],
};
