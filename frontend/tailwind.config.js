/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{js,jsx,ts,tsx}', './public/index.html'],
  theme: {
    extend: {
      boxShadow: {
        soft: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 4px 16px -2px rgb(15 23 42 / 0.06)',
        card: '0 1px 3px rgb(15 23 42 / 0.05), 0 12px 32px -14px rgb(15 23 42 / 0.18)',
        glow: '0 0 0 1px rgb(99 102 241 / 0.25), 0 10px 30px -8px rgb(99 102 241 / 0.45)',
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
        // Dropdown panel entrance: a quick scale-up + fade from the top-right.
        pop: {
          '0%': { opacity: '0', transform: 'scale(.96) translateY(-4px)' },
          '100%': { opacity: '1', transform: 'scale(1) translateY(0)' },
        },
        // Bell "ring" wiggle when a new notification arrives.
        bell: {
          '0%,100%': { transform: 'rotate(0)' },
          '15%': { transform: 'rotate(14deg)' },
          '30%': { transform: 'rotate(-12deg)' },
          '45%': { transform: 'rotate(9deg)' },
          '60%': { transform: 'rotate(-6deg)' },
          '75%': { transform: 'rotate(3deg)' },
        },
      },
      animation: {
        'fade-in-up': 'fade-in-up .45s cubic-bezier(.21,1.02,.73,1) both',
        fade: 'fade .35s ease-out both',
        float: 'float 6s ease-in-out infinite',
        pop: 'pop .16s cubic-bezier(.21,1.02,.73,1) both',
        bell: 'bell .9s ease-in-out',
      },
    },
  },
  plugins: [],
};
