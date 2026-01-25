import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './resources/js/**/*.js',
    ],

    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Noto Sans Thai', ...defaultTheme.fontFamily.sans],
                mono: ['JetBrains Mono', 'Fira Code', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                // Brand Colors
                primary: {
                    50: '#f0f9ff',
                    100: '#e0f2fe',
                    200: '#bae6fd',
                    300: '#7dd3fc',
                    400: '#38bdf8',
                    500: '#0ea5e9',
                    600: '#0284c7',
                    700: '#0369a1',
                    800: '#075985',
                    900: '#0c4a6e',
                    950: '#082f49',
                },
                // Trading Colors
                trading: {
                    green: '#00C853',
                    'green-light': '#69F0AE',
                    'green-dark': '#00A844',
                    red: '#FF1744',
                    'red-light': '#FF5252',
                    'red-dark': '#D50000',
                    yellow: '#FFD600',
                    blue: '#2196F3',
                },
                // Glass Morphism Colors
                glass: {
                    white: 'rgba(255, 255, 255, 0.1)',
                    dark: 'rgba(0, 0, 0, 0.2)',
                    border: 'rgba(255, 255, 255, 0.18)',
                    'border-dark': 'rgba(255, 255, 255, 0.08)',
                },
                // Dark Theme Base
                dark: {
                    50: '#f8fafc',
                    100: '#f1f5f9',
                    200: '#e2e8f0',
                    300: '#cbd5e1',
                    400: '#94a3b8',
                    500: '#64748b',
                    600: '#475569',
                    700: '#334155',
                    800: '#1e293b',
                    850: '#172033',
                    900: '#0f172a',
                    950: '#020617',
                },
            },
            backgroundImage: {
                'gradient-radial': 'radial-gradient(var(--tw-gradient-stops))',
                'gradient-conic': 'conic-gradient(from 180deg at 50% 50%, var(--tw-gradient-stops))',
                'glass-gradient': 'linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.05) 100%)',
                'dark-gradient': 'linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%)',
                'glow-gradient': 'radial-gradient(ellipse at center, rgba(14, 165, 233, 0.15) 0%, transparent 70%)',
            },
            boxShadow: {
                'glass': '0 8px 32px 0 rgba(0, 0, 0, 0.37)',
                'glass-sm': '0 4px 16px 0 rgba(0, 0, 0, 0.25)',
                'glass-lg': '0 16px 48px 0 rgba(0, 0, 0, 0.45)',
                'glow': '0 0 20px rgba(14, 165, 233, 0.5)',
                'glow-sm': '0 0 10px rgba(14, 165, 233, 0.3)',
                'glow-lg': '0 0 40px rgba(14, 165, 233, 0.6)',
                'green-glow': '0 0 20px rgba(0, 200, 83, 0.5)',
                'red-glow': '0 0 20px rgba(255, 23, 68, 0.5)',
            },
            backdropBlur: {
                xs: '2px',
            },
            animation: {
                'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                'glow': 'glow 2s ease-in-out infinite alternate',
                'float': 'float 6s ease-in-out infinite',
                'slide-up': 'slideUp 0.3s ease-out',
                'slide-down': 'slideDown 0.3s ease-out',
                'fade-in': 'fadeIn 0.3s ease-out',
                'scale-in': 'scaleIn 0.2s ease-out',
                'ticker': 'ticker 20s linear infinite',
            },
            keyframes: {
                glow: {
                    '0%': { boxShadow: '0 0 20px rgba(14, 165, 233, 0.3)' },
                    '100%': { boxShadow: '0 0 30px rgba(14, 165, 233, 0.6)' },
                },
                float: {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%': { transform: 'translateY(-10px)' },
                },
                slideUp: {
                    '0%': { transform: 'translateY(10px)', opacity: '0' },
                    '100%': { transform: 'translateY(0)', opacity: '1' },
                },
                slideDown: {
                    '0%': { transform: 'translateY(-10px)', opacity: '0' },
                    '100%': { transform: 'translateY(0)', opacity: '1' },
                },
                fadeIn: {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                scaleIn: {
                    '0%': { transform: 'scale(0.95)', opacity: '0' },
                    '100%': { transform: 'scale(1)', opacity: '1' },
                },
                ticker: {
                    '0%': { transform: 'translateX(0)' },
                    '100%': { transform: 'translateX(-50%)' },
                },
            },
            borderRadius: {
                '4xl': '2rem',
                '5xl': '2.5rem',
            },
        },
    },

    plugins: [forms],
};
