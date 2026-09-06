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
                white: 'rgb(var(--c-white) / <alpha-value>)',
                black: 'rgb(var(--c-black) / <alpha-value>)',
                // Brand Colors - Cyan (matches logo primary glow)
                primary: {
                    50: 'rgb(var(--c-primary-50) / <alpha-value>)',
                    100: 'rgb(var(--c-primary-100) / <alpha-value>)',
                    200: 'rgb(var(--c-primary-200) / <alpha-value>)',
                    300: 'rgb(var(--c-primary-300) / <alpha-value>)',
                    400: 'rgb(var(--c-primary-400) / <alpha-value>)',
                    500: 'rgb(var(--c-primary-500) / <alpha-value>)',
                    600: 'rgb(var(--c-primary-600) / <alpha-value>)',
                    700: 'rgb(var(--c-primary-700) / <alpha-value>)',
                    800: 'rgb(var(--c-primary-800) / <alpha-value>)',
                    900: 'rgb(var(--c-primary-900) / <alpha-value>)',
                    950: 'rgb(var(--c-primary-950) / <alpha-value>)',
                },
                // Accent Colors - Purple/Violet (logo left gradient)
                accent: {
                    50: 'rgb(var(--c-accent-50) / <alpha-value>)',
                    100: 'rgb(var(--c-accent-100) / <alpha-value>)',
                    200: 'rgb(var(--c-accent-200) / <alpha-value>)',
                    300: 'rgb(var(--c-accent-300) / <alpha-value>)',
                    400: 'rgb(var(--c-accent-400) / <alpha-value>)',
                    500: 'rgb(var(--c-accent-500) / <alpha-value>)',
                    600: 'rgb(var(--c-accent-600) / <alpha-value>)',
                    700: 'rgb(var(--c-accent-700) / <alpha-value>)',
                    800: 'rgb(var(--c-accent-800) / <alpha-value>)',
                    900: 'rgb(var(--c-accent-900) / <alpha-value>)',
                    950: 'rgb(var(--c-accent-950) / <alpha-value>)',
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
                    green: 'rgb(var(--c-trading-green) / <alpha-value>)',
                    'green-light': 'rgb(var(--c-trading-green-light) / <alpha-value>)',
                    'green-dark': 'rgb(var(--c-trading-green-dark) / <alpha-value>)',
                    red: 'rgb(var(--c-trading-red) / <alpha-value>)',
                    'red-light': 'rgb(var(--c-trading-red-light) / <alpha-value>)',
                    'red-dark': 'rgb(var(--c-trading-red-dark) / <alpha-value>)',
                    yellow: 'rgb(var(--c-trading-yellow) / <alpha-value>)',
                    blue: 'rgb(var(--c-trading-blue) / <alpha-value>)',
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
                    50: 'rgb(var(--c-dark-50) / <alpha-value>)',
                    100: 'rgb(var(--c-dark-100) / <alpha-value>)',
                    200: 'rgb(var(--c-dark-200) / <alpha-value>)',
                    300: 'rgb(var(--c-dark-300) / <alpha-value>)',
                    400: 'rgb(var(--c-dark-400) / <alpha-value>)',
                    500: 'rgb(var(--c-dark-500) / <alpha-value>)',
                    600: 'rgb(var(--c-dark-600) / <alpha-value>)',
                    700: 'rgb(var(--c-dark-700) / <alpha-value>)',
                    800: 'rgb(var(--c-dark-800) / <alpha-value>)',
                    850: 'rgb(var(--c-dark-850) / <alpha-value>)',
                    900: 'rgb(var(--c-dark-900) / <alpha-value>)',
                    950: 'rgb(var(--c-dark-950) / <alpha-value>)',
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
