import './bootstrap';
import intersect from '@alpinejs/intersect';

// Image cache for cross-page persistence (exposed globally for Alpine access)
const imageCache = new Map();
window.imageCache = imageCache;

// Preload an image and cache it
function preloadImage(url) {
    if (!url || imageCache.has(url)) {
        return Promise.resolve(imageCache.get(url) || url);
    }
    
    return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
            imageCache.set(url, url);
            resolve(url);
        };
        img.onerror = () => resolve(url);
        img.src = url;
    });
}

// Check if an image is already cached
function isImageCached(url) {
    return imageCache.has(url);
}

// Register intersect plugin and LQIP component with Alpine (Flux loads Alpine)
document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(intersect);
    
    // LQIP Image Component
    window.Alpine.data('lqipImage', (fullUrl, thumbUrl) => ({
        imageUrl: fullUrl,
        thumbUrl: thumbUrl,
        loaded: isImageCached(fullUrl),
        
        init() {
            // If already cached, mark as loaded immediately
            if (isImageCached(fullUrl)) {
                this.loaded = true;
            }
        },
        
        onLoad() {
            this.loaded = true;
            imageCache.set(fullUrl, fullUrl);
        }
    }));
});

// Prefetch images on hover/focus for faster navigation
document.addEventListener('DOMContentLoaded', () => {
    // Observe links with wire:navigate for prefetching
    document.addEventListener('mouseover', (e) => {
        const link = e.target.closest('a[wire\\:navigate], a[wire\\:navigate\\.hover]');
        if (!link) return;
        
        // Prefetch images from the linked page's data if available
        const href = link.getAttribute('href');
        if (href && !link.dataset.prefetched) {
            link.dataset.prefetched = 'true';
            // Mark for prefetch - Livewire handles the navigation prefetch
        }
    });
});

// Persist image cache across Livewire navigations
document.addEventListener('livewire:navigating', () => {
    // Cache is already in memory, nothing special needed
});

// Preload images on page after Livewire navigation completes
document.addEventListener('livewire:navigated', () => {
    // Find all LQIP images in the new page and preload them
    requestIdleCallback(() => {
        const images = document.querySelectorAll('[x-data*="lqipImage"]');
        images.forEach(el => {
            const match = el.getAttribute('x-data')?.match(/lqipImage\('([^']+)'/);
            if (match && match[1]) {
                preloadImage(match[1]);
            }
        });
    }, { timeout: 2000 });
});

// Expose to window for debugging and manual preloading
window.GSCImageCache = {
    preload: preloadImage,
    isCached: isImageCached,
    getCache: () => imageCache,
    preloadMultiple: (urls) => Promise.all(urls.map(preloadImage))
};
