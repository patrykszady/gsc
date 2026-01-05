import './bootstrap';
import intersect from '@alpinejs/intersect';

// Register intersect plugin with Alpine (Flux loads Alpine)
document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(intersect);
});
