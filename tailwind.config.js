/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    "./app/Views/**/*.php",
    "./public/index.php",
    "./app/Helpers/**/*.php"
  ],
  theme: {
    extend: {
      colors: {
        primary: '#3b82f6',
        dark: '#0f172a',
      }
    },
  },
  plugins: [],
}
