export function createProjectZipMap(zipPoints, maxCount, mapCenter) {
    return {
        map: null,
        circles: [],
        animationId: null,
        initialized: false,
        async initMap() {
            if (this.initialized) return;
            this.initialized = true;

            // Await the pre-warm promise set up in <head> — by the time Alpine
            // calls init(), the API is usually already downloaded.
            await (window.__mapsPrewarm || this.waitForGoogleMaps());
            if (!window.google?.maps?.importLibrary) return;

            const { Map } = await google.maps.importLibrary('maps');
            await google.maps.importLibrary('geocoding');

            // Start with map locked - requires click to interact
            this.map = new Map(this.$refs.map, {
                center: mapCenter,
                zoom: 10,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
                zoomControl: false,
                gestureHandling: 'cooperative',
                draggable: true,
                scrollwheel: true,
                disableDoubleClickZoom: false,
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

            this.renderZipCircles(zipPoints, Math.max(maxCount, 1));
            this.startPulseAnimation();
        },
        startPulseAnimation() {
            if (this.animationId) {
                cancelAnimationFrame(this.animationId);
            }

            let time = 0;
            const animate = () => {
                time += 0.05;

                this.circles.forEach((circle, i) => {
                    const phase = time + (i * 0.6);
                    const pulse = Math.sin(phase);

                    const scale = 1 + pulse * 0.08;
                    circle.setRadius(circle.baseRadius * scale);

                    const opacity = 0.30 + pulse * 0.05;
                    circle.setOptions({ fillOpacity: opacity });
                });

                this.animationId = requestAnimationFrame(animate);
            };
            this.animationId = requestAnimationFrame(animate);
        },
        renderZipCircles(zipPoints, maxCountSafe) {
            this.circles.forEach((c) => c.setMap(null));
            this.circles = [];

            const validPoints = (zipPoints || []).filter((point) => {
                return Number.isFinite(point?.lat) && Number.isFinite(point?.lng) && Number.isFinite(point?.count);
            });

            const plottedPoints = this.spreadOverlappingPoints(validPoints);

            plottedPoints.forEach((point) => {
                const intensity = Math.sqrt(point.count / maxCountSafe);
                const baseRadius = 2000 + intensity * 8000;

                const circle = new google.maps.Circle({
                    map: this.map,
                    center: { lat: point.plotLat, lng: point.plotLng },
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

            if (plottedPoints.length > 0) {
                const avg = plottedPoints.reduce(
                    (acc, point) => ({
                        lat: acc.lat + point.plotLat,
                        lng: acc.lng + point.plotLng,
                    }),
                    { lat: 0, lng: 0 }
                );
                this.map.setCenter({
                    lat: avg.lat / plottedPoints.length,
                    lng: avg.lng / plottedPoints.length,
                });
                this.map.setZoom(11);
            }
        },
        spreadOverlappingPoints(points) {
            const groups = new Map();

            points.forEach((point) => {
                const key = `${point.lat.toFixed(7)}|${point.lng.toFixed(7)}`;
                const bucket = groups.get(key) || [];
                bucket.push(point);
                groups.set(key, bucket);
            });

            const out = [];
            groups.forEach((group) => {
                if (group.length === 1) {
                    const only = group[0];
                    out.push({ ...only, plotLat: only.lat, plotLng: only.lng });
                    return;
                }

                const sorted = [...group].sort((a, b) => String(a.zip || '').localeCompare(String(b.zip || '')));
                const pointsPerRing = 8;
                const baseRadiusMeters = 140;
                const ringStepMeters = 140;
                const crowdBonusMeters = Math.min(260, Math.max(0, (sorted.length - 2) * 20));

                sorted.forEach((point, index) => {
                    const ringIndex = Math.floor(index / pointsPerRing);
                    const indexInRing = index % pointsPerRing;
                    const ringStart = ringIndex * pointsPerRing;
                    const pointsInThisRing = Math.min(pointsPerRing, sorted.length - ringStart);
                    const angle = ((2 * Math.PI * indexInRing) / pointsInThisRing) + (ringIndex * 0.35);
                    const offsetMeters = baseRadiusMeters + (ringIndex * ringStepMeters) + crowdBonusMeters;
                    const latRad = (point.lat * Math.PI) / 180;
                    const dLat = (offsetMeters * Math.sin(angle)) / 111320;
                    const cosLat = Math.cos(latRad);
                    const dLng = (offsetMeters * Math.cos(angle)) / (111320 * (Math.abs(cosLat) < 0.0001 ? 0.0001 : cosLat));

                    out.push({
                        ...point,
                        plotLat: point.lat + dLat,
                        plotLng: point.lng + dLng,
                    });
                });
            });

            return out;
        },
        waitForGoogleMaps() {
            // If already loaded (e.g. after wire:navigate), resolve immediately
            if (window.google?.maps?.importLibrary) return Promise.resolve();
            return new Promise((resolve) => {
                let attempts = 0;
                const timer = setInterval(() => {
                    attempts += 1;
                    if (window.google?.maps?.importLibrary || attempts > 40) {
                        clearInterval(timer);
                        resolve();
                    }
                }, 250);
            });
        },
    };
}
