/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts}'],
  theme: {
    extend: {
      colors: {
        paper: 'var(--paper)',
        card: 'var(--card)',
        ink: 'var(--ink)',
        muted: 'var(--ink-2)',
        seal: 'var(--seal)',
        indigo: 'var(--indigo)',
        moss: 'var(--moss)',
        gold: 'var(--gold)',
        line: 'var(--line)',
      },
      borderRadius: { paper: 'var(--r)' },
      boxShadow: { paper: 'var(--shadow)', elevated: 'var(--shadow-lg)' },
    },
  },
  plugins: [],
};
