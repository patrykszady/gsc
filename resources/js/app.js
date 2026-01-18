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

    // Project ZIP Map Component
    window.Alpine.data('projectZipMap', (zipCounts, maxCount, mapCenter) => ({
        map: null,
        geocoder: null,
        circles: [],
        animationId: null,
        initialized: false,
        async init() {
            if (this.initialized) return;
            this.initialized = true;
            
            await this.waitForGoogleMaps();
            if (!window.google?.maps?.importLibrary) return;

            const { Map } = await google.maps.importLibrary('maps');
            await google.maps.importLibrary('geocoding');

            this.map = new Map(this.$refs.map, {
                center: mapCenter,
                zoom: 10,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
                zoomControl: false,
                gestureHandling: 'cooperative',
                draggable: false,
                scrollwheel: false,
                keyboardShortcuts: false,
                minZoom: 9,
                maxZoom: 15,
                styles: [
                    { elementType: 'geometry', stylers: [{ color: '#f4f6f9' }] },
                    { elementType: 'labels.text.fill', stylers: [{ color: '#4b5563' }] },
                    { elementType: 'labels.text.stroke', stylers: [{ color: '#f4f6f9' }] },
                    { featureType: 'administrative', elementType: 'geometry.stroke', stylers: [{ color: '#d1d5db' }] },
                    { featureType: 'poi', elementType: 'geometry', stylers: [{ color: '#eef2f7' }] },
                    { featureType: 'poi', elementType: 'labels.text.fill', stylers: [{ color: '#6b7280' }] },
                    { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#ffffff' }] },
                    { featureType: 'road', elementType: 'geometry.stroke', stylers: [{ color: '#e5e7eb' }] },
                    { featureType: 'road', elementType: 'labels.text.fill', stylers: [{ color: '#6b7280' }] },
                    { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#1e3a5f' }] },
                    { featureType: 'water', elementType: 'labels.text.fill', stylers: [{ color: '#f4f6f9' }] },
                ],
            });

            this.geocoder = new google.maps.Geocoder();
            await this.renderZipCircles(zipCounts, Math.max(maxCount, 1));
            this.startPulseAnimation();
        },
        startPulseAnimation() {
            // Cancel any existing animation
            if (this.animationId) {
                cancelAnimationFrame(this.animationId);
            }
            
            // Each circle pulses independently with its own phase offset
            let time = 0;
            const animate = () => {
                time += 0.05; // Slightly faster pulse
                
                this.circles.forEach((circle, i) => {
                    // Each circle has its own phase offset for independent pulsing
                    const phase = time + (i * 0.6);
                    const pulse = Math.sin(phase);
                    
                    // Radius scales between 92% and 108% of base
                    const scale = 1 + pulse * 0.08;
                    circle.setRadius(circle.baseRadius * scale);
                    
                    // Moderate opacity pulse
                    const opacity = 0.30 + pulse * 0.05;
                    circle.setOptions({ fillOpacity: opacity });
                });
                
                this.animationId = requestAnimationFrame(animate);
            };
            this.animationId = requestAnimationFrame(animate);
        },
        async renderZipCircles(zipCounts, maxCountSafe) {
            // Clear any existing circles first
            this.circles.forEach(c => c.setMap(null));
            this.circles = [];
            
            const points = await Promise.all(zipCounts.map(async (zipData) => {
                const location = await this.geocodeZip(zipData.zip);
                if (!location) return null;
                return { ...zipData, location };
            }));

            const validPoints = points.filter(Boolean);

            validPoints.forEach((point) => {
                const intensity = Math.sqrt(point.count / maxCountSafe);
                const baseRadius = 2000 + intensity * 8000;

                const circle = new google.maps.Circle({
                    map: this.map,
                    center: point.location,
                    radius: baseRadius,
                    fillColor: '#0ea5e9',
                    fillOpacity: 0.3,
                    strokeColor: '#0284c7',
                    strokeOpacity: 0.8,
                    strokeWeight: 1,
                });

                circle.baseRadius = baseRadius;
                this.circles.push(circle);
            });

            if (validPoints.length > 0) {
                const avg = validPoints.reduce(
                    (acc, point) => ({
                        lat: acc.lat + point.location.lat,
                        lng: acc.lng + point.location.lng,
                    }),
                    { lat: 0, lng: 0 }
                );
                this.map.setCenter({
                    lat: avg.lat / validPoints.length,
                    lng: avg.lng / validPoints.length,
                });
                this.map.setZoom(11);
            }
        },
        waitForGoogleMaps() {
            return new Promise((resolve) => {
                let attempts = 0;
                const timer = setInterval(() => {
                    attempts += 1;
                    if (window.google?.maps?.importLibrary || attempts > 20) {
                        clearInterval(timer);
                        resolve();
                    }
                }, 250);
            });
        },
        async geocodeZip(zip) {
            const cached = this.getCachedZip(zip);
            if (cached) return cached;

            return new Promise((resolve) => {
                this.geocoder.geocode({ address: `${zip} USA` }, (results, status) => {
                    if (status === 'OK' && results[0]) {
                        const loc = results[0].geometry.location;
                        const coords = { lat: loc.lat(), lng: loc.lng() };
                        this.setCachedZip(zip, coords);
                        resolve(coords);
                        return;
                    }
                    resolve(null);
                });
            });
        },
        getCachedZip(zip) {
            try {
                const cache = JSON.parse(localStorage.getItem('zipGeoCache') || '{}');
                return cache[zip] || null;
            } catch (e) {
                return null;
            }
        },
        setCachedZip(zip, coords) {
            try {
                const cache = JSON.parse(localStorage.getItem('zipGeoCache') || '{}');
                cache[zip] = coords;
                localStorage.setItem('zipGeoCache', JSON.stringify(cache));
            } catch (e) {
                // Ignore cache write failures
            }
        },
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
