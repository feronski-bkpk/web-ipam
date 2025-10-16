document.addEventListener('DOMContentLoaded', function() {
    console.log('Web-IPAM system loaded successfully');
    
    function updateCurrentTime() {
        const timeElement = document.querySelector('.current-time');
        if (timeElement) {
            const now = new Date();
            timeElement.textContent = now.toLocaleString('ru-RU');
        }
    }
    
    setInterval(updateCurrentTime, 1000);
    updateCurrentTime();
    
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelector(this.getAttribute('href')).scrollIntoView({
                behavior: 'smooth'
            });
        });
    });
});