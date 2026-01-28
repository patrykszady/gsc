import './bootstrap';
import intersect from '@alpinejs/intersect';

// Image cache for cross-page persistence (exposed globally for Alpine access)
const imageCache = new Map();
window.imageCache = imageCache;

// Priority queue for background preloading (high priority items processed first)
const highPriorityQueue = [];
const lowPriorityQueue = [];
let isPreloading = false;

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

// Add images to background preload queue
function queuePreload(urls, highPriority = false) {
    const queue = highPriority ? highPriorityQueue : lowPriorityQueue;
    urls.forEach(url => {
        if (url && !imageCache.has(url) && !highPriorityQueue.includes(url) && !lowPriorityQueue.includes(url)) {
            queue.push(url);
        }
    });
    processPreloadQueue();
}

// Move URLs to high priority (for current page images)
function prioritizeUrls(urls) {
    urls.forEach(url => {
        if (!url || imageCache.has(url)) return;
        
        // Remove from low priority if exists
        const lowIndex = lowPriorityQueue.indexOf(url);
        if (lowIndex > -1) {
            lowPriorityQueue.splice(lowIndex, 1);
        }
        
        // Add to front of high priority if not already there
        if (!highPriorityQueue.includes(url)) {
            highPriorityQueue.unshift(url);
        }
    });
    processPreloadQueue();
}

// Process the preload queue in background
function processPreloadQueue() {
    if (isPreloading) return;
    if (highPriorityQueue.length === 0 && lowPriorityQueue.length === 0) return;
    
    isPreloading = true;
    
    const processNext = () => {
        // Always process high priority first
        let url = highPriorityQueue.shift();
        if (!url) {
            url = lowPriorityQueue.shift();
        }
        
        if (!url) {
            isPreloading = false;
            return;
        }
        
        if (imageCache.has(url)) {
            // Already cached, move to next immediately
            requestIdleCallback(processNext, { timeout: 50 });
            return;
        }
        
        const img = new Image();
        img.onload = () => {
            imageCache.set(url, url);
            // Use requestIdleCallback for next image to not block main thread
            // Shorter timeout for high priority items
            const timeout = highPriorityQueue.length > 0 ? 50 : 100;
            requestIdleCallback(processNext, { timeout });
        };
        img.onerror = () => {
            requestIdleCallback(processNext, { timeout: 100 });
        };
        img.src = url;
    };
    
    // Start processing when browser is idle
    requestIdleCallback(processNext, { timeout: 500 });
}

// Collect all image URLs from the page
function collectPageImages() {
    const urls = new Set();
    
    // Get all images with src attributes
    document.querySelectorAll('img[src]').forEach(img => {
        if (img.src && img.src.startsWith('http')) {
            urls.add(img.src);
        }
    });
    
    // Get all lqip-image component URLs (both thumb and full)
    document.querySelectorAll('[x-ref="fullImg"]').forEach(img => {
        if (img.src) urls.add(img.src);
    });
    
    // Get srcset images
    document.querySelectorAll('img[srcset]').forEach(img => {
        const srcset = img.getAttribute('srcset');
        if (srcset) {
            srcset.split(',').forEach(src => {
                const url = src.trim().split(' ')[0];
                if (url && url.startsWith('http')) {
                    urls.add(url);
                }
            });
        }
    });
    
    // Get background images from inline styles
    document.querySelectorAll('[style*="background-image"]').forEach(el => {
        const match = el.style.backgroundImage.match(/url\(['"]?([^'"]+)['"]?\)/);
        if (match && match[1]) {
            urls.add(match[1]);
        }
    });
    
    return Array.from(urls);
}

// Fetch all project images from the API for background preloading
function fetchAllProjectImages() {
    fetch('/api/project-images', { 
        priority: 'low',
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(urls => {
        if (Array.isArray(urls) && urls.length > 0) {
            // Add all project images to low priority queue
            queuePreload(urls, false);
        }
    })
    .catch(() => {}); // Silently fail
}

// Prefetch images from linked pages (hover intent)
function prefetchLinkedPageImages(href) {
    // Don't prefetch external links
    if (!href || !href.startsWith('/') && !href.startsWith(window.location.origin)) {
        return;
    }
    
    // Fetch the page HTML and extract image URLs
    fetch(href, { 
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        priority: 'low'
    })
    .then(response => response.text())
    .then(html => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const urls = [];
        
        doc.querySelectorAll('img[src]').forEach(img => {
            const src = img.getAttribute('src');
            if (src && (src.startsWith('http') || src.startsWith('/storage'))) {
                urls.push(src.startsWith('/') ? window.location.origin + src : src);
            }
        });
        
        if (urls.length > 0) {
            queuePreload(urls, false);
        }
    })
    .catch(() => {}); // Silently fail
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

// Lazy-load the Project ZIP Map module on demand
let projectZipMapLoader;
window.loadProjectZipMap = () => {
    if (!projectZipMapLoader) {
        projectZipMapLoader = import('./projectZipMap');
    }
    return projectZipMapLoader;
};

// Prefetch images on hover/focus for faster navigation
document.addEventListener('DOMContentLoaded', () => {
    // 1. First, prioritize and load current page images (HIGH PRIORITY)
    requestIdleCallback(() => {
        const pageImages = collectPageImages();
        queuePreload(pageImages, true); // High priority for current page
    }, { timeout: 500 });
    
    // 2. Then, fetch ALL project images for background loading (LOW PRIORITY)
    requestIdleCallback(() => {
        fetchAllProjectImages();
    }, { timeout: 3000 });
    
    // Observe links with wire:navigate for prefetching
    document.addEventListener('mouseover', (e) => {
        const link = e.target.closest('a[wire\\:navigate], a[wire\\:navigate\\.hover]');
        if (!link) return;

        // Prefetch images from the linked page's data if available
        const href = link.getAttribute('href');
        if (href && !link.dataset.prefetched) {
            link.dataset.prefetched = 'true';
            // Prefetch images from the linked page in background
            prefetchLinkedPageImages(href);
        }
    });

    // Smooth scroll for in-page anchors
    document.addEventListener('click', (e) => {
        const link = e.target.closest('a[href^="#"]');
        if (!link) return;

        const href = link.getAttribute('href');
        if (!href || href.length < 2) return;

        const target = document.querySelector(href);
        if (!target) return;

        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        history.replaceState(null, '', href);
    });
});

// Persist image cache across Livewire navigations
document.addEventListener('livewire:navigating', () => {
    // Cache is already in memory, nothing special needed
});

// Preload images on page after Livewire navigation completes
document.addEventListener('livewire:navigated', () => {
    // Prioritize current page images immediately
    requestIdleCallback(() => {
        const pageImages = collectPageImages();
        prioritizeUrls(pageImages); // Move current page images to front of queue
    }, { timeout: 100 });
});

// Expose to window for debugging and manual preloading
window.GSCImageCache = {
    preload: preloadImage,
    isCached: isImageCached,
    getCache: () => imageCache,
    preloadMultiple: (urls) => Promise.all(urls.map(preloadImage)),
    queuePreload: queuePreload,
    prioritize: prioritizeUrls,
    getQueueStatus: () => ({
        highPriority: highPriorityQueue.length,
        lowPriority: lowPriorityQueue.length,
        cached: imageCache.size
    })
};
