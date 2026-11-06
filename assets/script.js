document.addEventListener('DOMContentLoaded', function() {
    /* =========================================
       MOBILE MENU TOGGLE
       ========================================= */
    const menuToggle = document.getElementById('mobile-menu');
    const navMenu = document.getElementById('nav-menu');

    if (menuToggle && navMenu) {
        menuToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
            menuToggle.innerHTML = navMenu.classList.contains('active') ? '✕' : '☰';
        });
    }

    /* =========================================
       HERO BACKGROUND SLIDESHOW (right → left swipe)
       ========================================= */
    const slides = document.querySelectorAll('.hero-bg-slides .slide');
    if (slides.length === 0) return;

    let currentSlide = 0;
    const slideCount = slides.length;
    const intervalTime = 8000;   // 8 seconds per image
    const transitionTime = 1000; // 1 second swipe animation

    slides.forEach(s => {
        s.classList.remove('active', 'leaving');
    });
    slides[0].classList.add('active');

    function nextSlide() {
        const outgoing = slides[currentSlide];

        outgoing.classList.remove('active');
        outgoing.classList.add('leaving');

        currentSlide = (currentSlide + 1) % slideCount;
        const incoming = slides[currentSlide];

        incoming.classList.remove('leaving');
        void incoming.offsetWidth; 
        incoming.classList.add('active');

        setTimeout(() => {
            outgoing.classList.remove('leaving');
        }, transitionTime);
    }

    setInterval(nextSlide, intervalTime);
});