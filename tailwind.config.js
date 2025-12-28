/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    // Pindai semua file PHP di dalam folder Views (mencakup atoms, molecules, pages, dll)
    "./app/Views/**/*.php",
    
    // Opsional: Pindai file JS jika Anda melakukan manipulasi class via JS
    "./public/js/**/*.js",
    
    // Opsional: Pindai Pager/Pagination bawaan CI4 jika Anda memodifikasinya
    "./app/Config/Pager.php",
  ],
  theme: {
    extend: {
      // Disini kita bisa menambahkan warna custom 'brand' DevDaily nanti
      colors: {
        // 'primary': '#1a202c',
      },
      // Disini kita bisa set font-family offline (setelah font file didownload)
      fontFamily: {
        // 'sans': ['Inter', 'sans-serif'],
      }
    },
  },
  plugins: [],
}
