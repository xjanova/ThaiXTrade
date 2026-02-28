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
                // Brand Colors - Cyan (matches logo primary glow)
                primary: {
                    50: '#ecfeff',
                    100: '#cffafe',
                    200: '#a5f3fc',
                    300: '#67e8f9',
                    400: '#22d3ee',
                    500: '#06b6d4',
                    600: '#0891b2',
                    700: '#0e7490',
                    800: '#155e75',
                    900: '#164e63',
                    950: '#083344',
                },
                // Accent Colors - Purple/Violet (logo left gradient)
                accent: {
                    50: '#f5f3ff',
                    100: '#ede9fe',
                    200: '#ddd6fe',
                    300: '#c4b5fd',
                    400: '#a78bfa',
                    500: '#8b5cf6',
                    600: '#7c3aed',
                    700: '#6d28d9',
                    800: '#5b21b6',
                    900: '#4c1d95',
                    950: '#2e1065',
                },
                // Warm Colors - Orange/Coral (logo right gradient)
                warm: {
                    50: '#fff7ed',
                    100: '#ffedd5',
                    200: '#fed7aa',
                    300: '#fdba74',
                    400: '#fb923c',
                    500: '#f97316',
                    600: '#ea580c',
                    700: '#c2410c',
                    800: '#9a3412',
                    900: '#7c2d12',
                    950: '#431407',
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
                // Dark Theme Base (slightly more blue-purple tint to match logo bg)
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
                'dark-gradient': 'linear-gradient(135deg, #020617 0%, #0f172a 50%, #020617 100%)',
                'glow-gradient': 'radial-gradient(ellipse at center, rgba(6, 182, 212, 0.15) 0%, transparent 70%)',
                // Brand gradient matching logo: purple → cyan → orange
                'brand-gradient': 'linear-gradient(135deg, #8b5cf6 0%, #06b6d4 50%, #f97316 100%)',
                'brand-gradient-subtle': 'linear-gradient(135deg, rgba(139,92,246,0.15) 0%, rgba(6,182,212,0.15) 50%, rgba(249,115,22,0.08) 100%)',
            },
            boxShadow: {
                'glass': '0 8px 32px 0 rgba(0, 0, 0, 0.37)',
                'glass-sm': '0 4px 16px 0 rgba(0, 0, 0, 0.25)',
                'glass-lg': '0 16px 48px 0 rgba(0, 0, 0, 0.45)',
                'glow': '0 0 20px rgba(6, 182, 212, 0.5)',
                'glow-sm': '0 0 10px rgba(6, 182, 212, 0.3)',
                'glow-lg': '0 0 40px rgba(6, 182, 212, 0.6)',
                'glow-purple': '0 0 20px rgba(139, 92, 246, 0.5)',
                'glow-warm': '0 0 20px rgba(249, 115, 22, 0.5)',
                'glow-brand': '0 0 30px rgba(6, 182, 212, 0.3), 0 0 60px rgba(139, 92, 246, 0.15)',
                'green-glow': '0 0 20px rgba(0, 200, 83, 0.5)',
                'red-glow': '0 0 20px rgba(255, 23, 68, 0.5)',
            },
            backdropBlur: {
                xs: '2px',
            },
            animation: {
                'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                'glow': 'glow 2s ease-in-out infinite alternate',
                'glow-brand': 'glowBrand 3s ease-in-out infinite alternate',
                'float': 'float 6s ease-in-out infinite',
                'slide-up': 'slideUp 0.3s ease-out',
                'slide-down': 'slideDown 0.3s ease-out',
                'fade-in': 'fadeIn 0.3s ease-out',
                'scale-in': 'scaleIn 0.2s ease-out',
                'ticker': 'ticker 20s linear infinite',
                'gradient-shift': 'gradientShift 6s ease infinite',
            },
            keyframes: {
                glow: {
                    '0%': { boxShadow: '0 0 20px rgba(6, 182, 212, 0.3)' },
                    '100%': { boxShadow: '0 0 30px rgba(6, 182, 212, 0.6)' },
                },
                glowBrand: {
                    '0%': { boxShadow: '0 0 20px rgba(139, 92, 246, 0.3), 0 0 40px rgba(6, 182, 212, 0.1)' },
                    '50%': { boxShadow: '0 0 25px rgba(6, 182, 212, 0.4), 0 0 50px rgba(139, 92, 246, 0.15)' },
                    '100%': { boxShadow: '0 0 20px rgba(249, 115, 22, 0.3), 0 0 40px rgba(6, 182, 212, 0.1)' },
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
                gradientShift: {
                    '0%, 100%': { backgroundPosition: '0% 50%' },
                    '50%': { backgroundPosition: '100% 50%' },
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
