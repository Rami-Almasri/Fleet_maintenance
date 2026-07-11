/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{js,jsx,ts,tsx}', './public/index.html'],
  // Dark mode driven by <html data-theme="dark"> (see ThemeContext + index.css).
  darkMode: ['selector', '[data-theme="dark"]'],
  theme: {
    extend: {
      fontFamily: {
        // Inter for body/UI, Sora for display headings, JetBrains for numerics.
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Helvetica', 'Arial', 'sans-serif'],
        display: ['Sora', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
      },
      colors: {
        // ---- Brand: navy-blue (replaces the old indigo/violet identity). ----
        // Driven by CSS vars so opacity modifiers (e.g. bg-indigo-500/20) work.
        indigo: {
          50: 'rgb(var(--brand-50) / <alpha-value>)', 100: 'rgb(var(--brand-100) / <alpha-value>)',
          200: 'rgb(var(--brand-200) / <alpha-value>)', 300: 'rgb(var(--brand-300) / <alpha-value>)',
          400: 'rgb(var(--brand-400) / <alpha-value>)', 500: 'rgb(var(--brand-500) / <alpha-value>)',
          600: 'rgb(var(--brand-600) / <alpha-value>)', 700: 'rgb(var(--brand-700) / <alpha-value>)',
          800: 'rgb(var(--brand-800) / <alpha-value>)', 900: 'rgb(var(--brand-900) / <alpha-value>)',
          950: 'rgb(var(--brand-950) / <alpha-value>)',
        },
        brand: {
          50: 'rgb(var(--brand-50) / <alpha-value>)', 100: 'rgb(var(--brand-100) / <alpha-value>)',
          200: 'rgb(var(--brand-200) / <alpha-value>)', 300: 'rgb(var(--brand-300) / <alpha-value>)',
          400: 'rgb(var(--brand-400) / <alpha-value>)', 500: 'rgb(var(--brand-500) / <alpha-value>)',
          600: 'rgb(var(--brand-600) / <alpha-value>)', 700: 'rgb(var(--brand-700) / <alpha-value>)',
          800: 'rgb(var(--brand-800) / <alpha-value>)', 900: 'rgb(var(--brand-900) / <alpha-value>)',
          950: 'rgb(var(--brand-950) / <alpha-value>)',
        },
        // Secondary brand accent (cyan/sky) — gives gradients a "command center" sheen.
        violet: {
          50: 'rgb(var(--accent-50) / <alpha-value>)', 100: 'rgb(var(--accent-100) / <alpha-value>)',
          200: 'rgb(var(--accent-200) / <alpha-value>)', 300: 'rgb(var(--accent-300) / <alpha-value>)',
          400: 'rgb(var(--accent-400) / <alpha-value>)', 500: 'rgb(var(--accent-500) / <alpha-value>)',
          600: 'rgb(var(--accent-600) / <alpha-value>)', 700: 'rgb(var(--accent-700) / <alpha-value>)',
          800: 'rgb(var(--accent-800) / <alpha-value>)', 900: 'rgb(var(--accent-900) / <alpha-value>)',
          950: 'rgb(var(--brand-950) / <alpha-value>)',
        },
        // ---- Fixed chrome palette (never inverts): the command-center shell. ----
        navy: {
          950: '#05080f', 900: '#0a1020', 850: '#0d1426', 800: '#111a30',
          700: '#172441', 600: '#1f2f52', 500: '#293c66',
        },
        steel: {
          100: '#e3e8f0', 200: '#c7d0e0', 300: '#9aa7bd', 400: '#7c8aa3',
          500: '#5e6b82', 600: '#46526a', 700: '#33405a',
        },
        // ---- Operational semantics ----
        alert:   { 50: '#fff7ed', 100: '#ffedd5', 200: '#fed7aa', 400: '#fb923c', 500: '#f97316', 600: '#ea580c', 700: '#c2410c', DEFAULT: '#f97316' },
        success: { 50: '#ecfdf5', 100: '#d1fae5', 200: '#a7f3d0', 400: '#34d399', 500: '#10b981', 600: '#059669', 700: '#047857', DEFAULT: '#10b981' },
      },
      boxShadow: {
        soft: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 4px 16px -2px rgb(15 23 42 / 0.06)',
        card: '0 1px 3px rgb(15 23 42 / 0.05), 0 12px 32px -14px rgb(15 23 42 / 0.18)',
        glow: '0 0 0 1px rgb(var(--brand-500) / 0.25), 0 10px 30px -8px rgb(var(--brand-500) / 0.45)',
        'glow-lg': '0 0 0 1px rgb(var(--brand-500) / 0.3), 0 18px 50px -10px rgb(var(--brand-500) / 0.55)',
      },
      keyframes: {
        'fade-in-up': {
          '0%': { opacity: '0', transform: 'translateY(8px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        fade: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
        shimmer: { '100%': { transform: 'translateX(100%)' } },
        float: {
          '0%,100%': { transform: 'translateY(0)' },
          '50%': { transform: 'translateY(-6px)' },
        },
        pop: {
          '0%': { opacity: '0', transform: 'scale(.96) translateY(-4px)' },
          '100%': { opacity: '1', transform: 'scale(1) translateY(0)' },
        },
        bell: {
          '0%,100%': { transform: 'rotate(0)' },
          '15%': { transform: 'rotate(14deg)' }, '30%': { transform: 'rotate(-12deg)' },
          '45%': { transform: 'rotate(9deg)' }, '60%': { transform: 'rotate(-6deg)' },
          '75%': { transform: 'rotate(3deg)' },
        },
        // Sweep used by gauges / progress bars when they fill.
        sweep: { '0%': { strokeDashoffset: 'var(--dash, 100)' }, '100%': { strokeDashoffset: 'var(--off, 0)' } },
        // Right-side slide-over drawer entrance.
        'slide-in-right': { '0%': { transform: 'translateX(100%)' }, '100%': { transform: 'translateX(0)' } },
        // Attention pulse for the deep-linked / highlighted board card.
        'pulse-ring': {
          '0%,100%': { boxShadow: '0 0 0 0 rgb(var(--brand-500) / 0.5)' },
          '50%':     { boxShadow: '0 0 0 7px rgb(var(--brand-500) / 0)' },
        },
      },
      animation: {
        'fade-in-up': 'fade-in-up .45s cubic-bezier(.21,1.02,.73,1) both',
        fade: 'fade .35s ease-out both',
        float: 'float 6s ease-in-out infinite',
        pop: 'pop .16s cubic-bezier(.21,1.02,.73,1) both',
        bell: 'bell .9s ease-in-out',
        'slide-in-right': 'slide-in-right .28s cubic-bezier(.32,.72,0,1) both',
        'pulse-ring': 'pulse-ring 1.6s ease-out infinite',
      },
    },
  },
  plugins: [],
};
