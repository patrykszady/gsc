@props([
    'containerClass' => '',
    'imageClass' => 'w-full h-auto',
    'rounded' => 'rounded-2xl',
])

{{--
    Zoomable Image Component
    
    This component provides pinch-to-zoom and wheel-to-zoom functionality for images.
    It requires the parent to provide these Alpine.js variables/methods:
    - current: { url, alt, ... } - The current image object
    - images: [] - Array of all images (for navigation)
    - currentIndex: number - Current image index
    - prev(): function - Navigate to previous image
    - next(): function - Navigate to next image
    
    Usage:
    <x-zoomable-image container-class="..." image-class="...">
        <x-slot:overlays>
            ... Your overlays here (dots, captions, etc.) ...
        </x-slot:overlays>
    </x-zoomable-image>
--}}

<div 
    x-data="{
        // Loading state
        imageLoaded: false,
        
        // Zoom state
        scale: 1,
        translateX: 0,
        translateY: 0,
        isZoomed: false,
        minScale: 1,
        maxScale: 4,
        
        // Touch/pinch state
        initialPinchDistance: null,
        initialScale: 1,
        lastTouchX: 0,
        lastTouchY: 0,
        isPanning: false,
        touchStartX: 0,
        touchStartY: 0,
        swiping: false,
        
        resetZoom() {
            this.scale = 1;
            this.translateX = 0;
            this.translateY = 0;
            this.isZoomed = false;
            this.$dispatch('zoom-changed', { isZoomed: false });
        },
        
        constrainTranslation() {
            if (this.scale <= 1) {
                this.translateX = 0;
                this.translateY = 0;
                return;
            }
            const container = this.$refs.zoomContainer;
            if (!container) return;
            const img = container.querySelector('img');
            if (!img) return;
            
            const containerRect = container.getBoundingClientRect();
            const scaledWidth = img.offsetWidth * this.scale;
            const scaledHeight = img.offsetHeight * this.scale;
            
            const maxX = Math.max(0, (scaledWidth - containerRect.width) / 2);
            const maxY = Math.max(0, (scaledHeight - containerRect.height) / 2);
            
            this.translateX = Math.max(-maxX, Math.min(maxX, this.translateX));
            this.translateY = Math.max(-maxY, Math.min(maxY, this.translateY));
        },
        
        handleWheel(e) {
            e.preventDefault();
            const delta = e.deltaY > 0 ? 0.9 : 1.1;
            const newScale = Math.max(this.minScale, Math.min(this.maxScale, this.scale * delta));
            
            if (newScale !== this.scale) {
                const container = this.$refs.zoomContainer;
                if (!container) return;
                const rect = container.getBoundingClientRect();
                const x = e.clientX - rect.left - rect.width / 2;
                const y = e.clientY - rect.top - rect.height / 2;
                
                const scaleFactor = newScale / this.scale;
                this.translateX = x - (x - this.translateX) * scaleFactor;
                this.translateY = y - (y - this.translateY) * scaleFactor;
                
                this.scale = newScale;
                this.isZoomed = this.scale > 1.05;
                this.constrainTranslation();
                this.$dispatch('zoom-changed', { isZoomed: this.isZoomed });
            }
        },
        
        handleDoubleTap(e) {
            if (this.isZoomed) {
                this.resetZoom();
            } else {
                const container = this.$refs.zoomContainer;
                if (!container) return;
                
                const rect = container.getBoundingClientRect();
                const x = e.clientX - rect.left - rect.width / 2;
                const y = e.clientY - rect.top - rect.height / 2;
                
                this.scale = 2;
                this.translateX = -x;
                this.translateY = -y;
                this.isZoomed = true;
                this.constrainTranslation();
                this.$dispatch('zoom-changed', { isZoomed: true });
            }
        },
        
        handleImageClick(e) {
            if (this.isZoomed) return;
            
            const rect = e.currentTarget.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const width = rect.width;
            if (x < width / 3) {
                prev();
            } else if (x > width * 2 / 3) {
                next();
            }
        },
        
        handleTouchStart(e) {
            if (e.touches.length === 2) {
                this.initialPinchDistance = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
                this.initialScale = this.scale;
                this.swiping = false;
            } else if (e.touches.length === 1) {
                this.touchStartX = e.touches[0].clientX;
                this.touchStartY = e.touches[0].clientY;
                this.lastTouchX = e.touches[0].clientX;
                this.lastTouchY = e.touches[0].clientY;
                this.isPanning = this.isZoomed;
                this.swiping = !this.isZoomed;
            }
        },
        
        handleTouchMove(e) {
            if (e.touches.length === 2 && this.initialPinchDistance) {
                const distance = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
                const newScale = Math.max(this.minScale, Math.min(this.maxScale, 
                    this.initialScale * (distance / this.initialPinchDistance)
                ));
                
                this.scale = newScale;
                const wasZoomed = this.isZoomed;
                this.isZoomed = this.scale > 1.05;
                if (wasZoomed !== this.isZoomed) {
                    this.$dispatch('zoom-changed', { isZoomed: this.isZoomed });
                }
                this.constrainTranslation();
                e.preventDefault();
            } else if (e.touches.length === 1 && this.isZoomed && this.isPanning) {
                const deltaX = e.touches[0].clientX - this.lastTouchX;
                const deltaY = e.touches[0].clientY - this.lastTouchY;
                
                this.translateX += deltaX;
                this.translateY += deltaY;
                this.constrainTranslation();
                
                this.lastTouchX = e.touches[0].clientX;
                this.lastTouchY = e.touches[0].clientY;
                e.preventDefault();
            }
        },
        
        handleTouchEnd(e) {
            if (this.initialPinchDistance) {
                this.initialPinchDistance = null;
                if (this.scale < 1.05) {
                    this.resetZoom();
                }
                return;
            }
            
            this.isPanning = false;
            
            if (!this.isZoomed && this.swiping && e.changedTouches.length === 1) {
                const touchEndX = e.changedTouches[0].clientX;
                const touchEndY = e.changedTouches[0].clientY;
                const diffX = this.touchStartX - touchEndX;
                const diffY = this.touchStartY - touchEndY;
                if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 50) {
                    if (diffX > 0) { next(); } 
                    else { prev(); }
                }
            }
            this.swiping = false;
        },
        
        init() {
            // Reset zoom and loading state when image changes
            this.$watch('currentIndex', () => {
                this.resetZoom();
                this.imageLoaded = false;
            });
        }
    }"
    x-ref="zoomContainer"
    {{ $attributes->merge(['class' => $containerClass]) }}
    :class="isZoomed ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer'"
    @click="handleImageClick($event)"
    @dblclick="handleDoubleTap($event)"
    @wheel.prevent="handleWheel($event)"
    @touchstart="handleTouchStart($event)"
    @touchmove="handleTouchMove($event)"
    @touchend="handleTouchEnd($event)"
    @keydown.escape.window="isZoomed && resetZoom()"
>
    {{-- Image with zoom transform --}}
    <div class="relative select-none flex items-center justify-center overflow-hidden {{ $rounded }}" :class="isZoomed ? 'touch-none' : ''">
        <img 
            :src="current.url || current.webpUrl" 
            :alt="current.alt"
            class="{{ $imageClass }} transition-transform duration-100 ease-out select-none"
            :style="`transform: scale(${scale}) translate(${translateX / scale}px, ${translateY / scale}px); transform-origin: center center;`"
            draggable="false"
        >
        
        {{-- Zoom indicator --}}
        <div 
            x-show="isZoomed" 
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="absolute top-4 left-1/2 -translate-x-1/2 z-30"
        >
            <div class="bg-black/70 text-white px-3 py-1.5 rounded-full text-sm flex items-center gap-2">
                <span x-text="`${Math.round(scale * 100)}%`"></span>
                <button @click.stop="resetZoom()" class="hover:text-sky-300 transition-colors" title="Reset zoom">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
        
        {{-- Overlays slot (shown only when not zoomed) --}}
        <template x-if="!isZoomed">
            <div>
                {{ $overlays ?? '' }}
            </div>
        </template>
    </div>
</div>
