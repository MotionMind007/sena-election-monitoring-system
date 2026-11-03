<script setup lang="ts">
import papuaProvincesGeoJson from '@/Data/papua-provinces.json';
import papuaRegenciesGeoJson from '@/Data/papua-regencies.json';
import L, { type GeoJSON as LeafletGeoJson, type Layer, type Map as LeafletMap, type PathOptions } from 'leaflet';
import 'leaflet/dist/leaflet.css';
import type { Feature, FeatureCollection, Geometry } from 'geojson';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

type FeatureProperties = { namobj?: string; wadmpr?: string; NAMOBJ?: string; WADMPR?: string; WADMKK?: string; WADMKC?: string; WADMKD?: string };
type MapFeature = Feature<Geometry, FeatureProperties>;
type RegionProgress = { id: number; name: string; level?: string; total: number; submitted: number; verified: number };
type LabelLevel = 'province' | 'regency' | 'district' | 'village';
type LabelEntry = { feature: MapFeature; layer: L.Layer; level: LabelLevel; bounds: L.LatLngBounds };

const props = defineProps<{
    scope: { id: number; name: string; level: string };
    regions: RegionProgress[];
    summary: { total: number; submitted: number; verified: number };
}>();
const emit = defineEmits<{ select: [regionId: number] }>();

const mapElement = ref<HTMLElement | null>(null);
const zoomLevel = ref(0);
const activeLayerLabel = ref('Provinsi');
const basemapFailed = ref(false);
const isPapuaOverview = computed(() => props.scope.level === 'province' && normalizeName(props.scope.name) === 'papua');

let map: LeafletMap | null = null;
let resizeObserver: ResizeObserver | null = null;
let basemapLayer: L.TileLayer | null = null;
let provinceLayer: LeafletGeoJson | null = null;
let regencyLayer: LeafletGeoJson | null = null;
let districtLayer: LeafletGeoJson | null = null;
let villageLayer: LeafletGeoJson | null = null;
let labelLayer: L.LayerGroup | null = null;
let districtData: FeatureCollection<Geometry, FeatureProperties> | null = null;
let villageData: FeatureCollection<Geometry, FeatureProperties> | null = null;
let districtLoad: Promise<void> | null = null;
let villageLoad: Promise<void> | null = null;
const labels: LabelEntry[] = [];

const provinceData = papuaProvincesGeoJson as unknown as FeatureCollection<Geometry, FeatureProperties>;
const regencyData = papuaRegenciesGeoJson as unknown as FeatureCollection<Geometry, FeatureProperties>;

function normalizeName(name = ''): string {
    return name.toLocaleLowerCase('id-ID').replace(/^kab(?:upaten|\.)?\s+/, '').replace(/^kota\s+/, '').replace(/\s+/g, ' ').trim();
}

function featureName(feature: MapFeature): string {
    return feature.properties.NAMOBJ ?? feature.properties.WADMKD ?? feature.properties.namobj ?? feature.properties.wadmpr ?? feature.properties.WADMPR ?? '';
}

function regionFor(feature: MapFeature): RegionProgress | undefined {
    return props.regions.find((region) => normalizeName(region.name) === normalizeName(featureName(feature)));
}

function progressColor(feature: MapFeature): string {
    const region = regionFor(feature);
    if (!region || region.total === 0) return '#334155';
    const progress = (region.verified / region.total) * 100;
    if (progress >= 80) return '#10b981';
    if (progress >= 60) return '#22d3ee';
    if (progress >= 40) return '#facc15';
    if (progress >= 20) return '#fb923c';
    return '#f43f5e';
}

function provinceStyle(feature?: MapFeature): PathOptions {
    const active = normalizeName(feature ? featureName(feature) : '') === 'papua';
    return { color: active ? '#06b6d4' : '#475569', weight: active ? 2.8 : 1.6, fillColor: active ? '#0891b2' : '#64748b', fillOpacity: active ? 0.18 : 0.1 };
}

function tooltip(feature: MapFeature): string {
    const region = regionFor(feature);
    return region ? `${featureName(feature)} · ${region.verified}/${region.total} TPS terverifikasi` : featureName(feature);
}

function ringArea(ring: number[][]): number {
    return Math.abs(ring.reduce((sum, point, index) => {
        const next = ring[(index + 1) % ring.length];
        return sum + point[0] * next[1] - next[0] * point[1];
    }, 0) / 2);
}

function primaryBounds(feature: MapFeature): L.LatLngBounds {
    if (feature.geometry.type !== 'MultiPolygon') return L.geoJSON(feature).getBounds();
    const polygon = [...feature.geometry.coordinates].sort((left, right) => ringArea(right[0]) - ringArea(left[0]))[0];
    return L.geoJSON({ ...feature, geometry: { type: 'Polygon', coordinates: polygon } } as MapFeature).getBounds();
}

function bindFeature(feature: MapFeature, layer: Layer, level: LabelLevel): void {
    const bounds = primaryBounds(feature);
    labels.push({ feature, layer, level, bounds });
    layer.bindTooltip(tooltip(feature), { sticky: true, direction: 'top', className: 'sena-map-tooltip' });
    layer.on('click', () => {
        const region = regionFor(feature);
        if (region) {
            emit('select', region.id);
            return;
        }
        if (bounds?.isValid()) map?.fitBounds(bounds, { padding: [34, 34], maxZoom: level === 'village' ? 13 : 10 });
    });
}

function createLayers(): void {
    const renderer = L.canvas({ padding: 0.5 });
    provinceLayer = L.geoJSON(provinceData, {
        style: (feature) => ({ ...provinceStyle(feature as MapFeature), renderer }),
        onEachFeature: (feature, layer) => bindFeature(feature as MapFeature, layer, 'province'),
    });
    regencyLayer = L.geoJSON(regencyData, {
        style: (feature) => ({ color: '#0f172a', weight: 1.8, fillColor: progressColor(feature as MapFeature), fillOpacity: 0.34, renderer }),
        onEachFeature: (feature, layer) => bindFeature(feature as MapFeature, layer, 'regency'),
    });
}

async function ensureDistrictLayer(): Promise<void> {
    if (districtLayer) return;
    districtLoad ??= (async () => {
        const module = await import('@/Data/papua-districts.json');
        districtData = module.default as unknown as FeatureCollection<Geometry, FeatureProperties>;
        districtLayer = L.geoJSON(districtData, {
            style: { color: '#0891b2', weight: 1.35, fillColor: '#22d3ee', fillOpacity: 0.05, renderer: L.canvas({ padding: 0.5 }) },
            filter: (feature) => !featureName(feature as MapFeature).toLocaleLowerCase('id-ID').includes('tidak terdefinisi'),
            onEachFeature: (feature, layer) => bindFeature(feature as MapFeature, layer, 'district'),
        });
    })();
    await districtLoad;
}

async function ensureVillageLayer(): Promise<void> {
    if (villageLayer) return;
    villageLoad ??= (async () => {
        const module = await import('@/Data/papua-villages.json');
        villageData = module.default as unknown as FeatureCollection<Geometry, FeatureProperties>;
        villageLayer = L.geoJSON(villageData, {
            style: { color: '#0369a1', weight: 0.9, fillColor: '#38bdf8', fillOpacity: 0.025, renderer: L.canvas({ padding: 0.5 }) },
            filter: (feature) => !featureName(feature as MapFeature).toLocaleLowerCase('id-ID').includes('tidak terdefinisi'),
            onEachFeature: (feature, layer) => bindFeature(feature as MapFeature, layer, 'village'),
        });
    })();
    await villageLoad;
}

async function setLayerVisibility(): Promise<void> {
    if (!map || !provinceLayer || !regencyLayer) return;
    const zoom = map.getZoom();
    zoomLevel.value = zoom;
    const showRegencies = isPapuaOverview.value || ['regency', 'city', 'district', 'village'].includes(props.scope.level);
    const showDistricts = zoom >= 8 || ['district', 'village'].includes(props.scope.level);
    const showVillages = zoom >= 11 || props.scope.level === 'village';
    if (showDistricts) await ensureDistrictLayer();
    if (showVillages) await ensureVillageLayer();
    const desiredLayers: Array<[LeafletGeoJson | null, boolean]> = [
        [provinceLayer, true],
        [regencyLayer, showRegencies],
        [districtLayer, showDistricts],
        [villageLayer, showVillages],
    ];
    desiredLayers.forEach(([layer, visible]) => {
        if (!layer) return;
        if (visible && !map?.hasLayer(layer)) layer.addTo(map as LeafletMap);
        if (!visible && map?.hasLayer(layer)) map.removeLayer(layer);
    });
    activeLayerLabel.value = zoom >= 11 ? 'Kampung/Kelurahan' : zoom >= 8 ? 'Distrik' : showRegencies ? 'Kabupaten/Kota' : 'Provinsi';
    updateLabels();
}

function labelText(entry: LabelEntry): string {
    return featureName(entry.feature).replace('Kepulauan ', 'Kep. ').replace('Mamberamo ', 'Mamb. ');
}

function updateLabels(): void {
    if (!map || !labelLayer) return;
    labelLayer.clearLayers();
    const zoom = map.getZoom();
    const targetLevel: LabelLevel = zoom >= 11 ? 'village' : zoom >= 8 ? 'district' : zoom >= 6 ? 'regency' : 'province';
    const limit = targetLevel === 'village' ? 90 : targetLevel === 'district' ? 70 : 20;
    const viewport = map.getBounds().pad(0.08);
    const occupied: Array<{ left: number; right: number; top: number; bottom: number }> = [];
    const addLabel = (entry: LabelEntry, emphasized = false): void => {
        const bounds = entry.bounds;
        const point = map?.latLngToContainerPoint(bounds.getCenter());
        const text = labelText(entry);
        const width = Math.max(42, Math.min(110, text.length * 5.2 + 12));
        const box = point ? { left: point.x - width / 2, right: point.x + width / 2, top: point.y - 9, bottom: point.y + 9 } : null;
        const collides = box && occupied.some((item) => !(box.right < item.left || box.left > item.right || box.bottom < item.top || box.top > item.bottom));
        if (collides && !emphasized) return;
        if (box) occupied.push(box);
        const isSupiori = emphasized || normalizeName(featureName(entry.feature)) === 'supiori';
        L.marker(bounds.getCenter(), {
            interactive: false,
            icon: L.divIcon({
                className: 'sena-map-label-shell',
                html: `<span class="sena-map-label ${isSupiori ? 'sena-map-label--supiori' : ''}">${text}</span>`,
                iconSize: undefined,
            }),
        }).addTo(labelLayer as L.LayerGroup);
    };
    labels.filter((entry) => entry.level === targetLevel).filter((entry) => {
        return entry.bounds.isValid() && viewport.intersects(entry.bounds);
    }).sort((left, right) => {
        const leftCenter = left.bounds.getCenter();
        const rightCenter = right.bounds.getCenter();
        return leftCenter.distanceTo(map?.getCenter() ?? leftCenter) - rightCenter.distanceTo(map?.getCenter() ?? rightCenter);
    }).slice(0, limit).forEach((entry) => addLabel(entry));

    if (isPapuaOverview.value && zoom < 6) {
        const supiori = labels.find((entry) => entry.level === 'regency' && normalizeName(featureName(entry.feature)) === 'supiori');
        if (supiori) addLabel(supiori, true);
    }
}

function featureBounds(collection: FeatureCollection<Geometry, FeatureProperties>, name: string): L.LatLngBounds | null {
    const feature = collection.features.find((item) => normalizeName(featureName(item)) === normalizeName(name));
    if (!feature) return null;
    const bounds = primaryBounds(feature);
    return bounds.isValid() ? bounds : null;
}

async function fitInitialScope(): Promise<void> {
    if (!map || !provinceLayer) return;
    let bounds: L.LatLngBounds | null = null;
    if (isPapuaOverview.value) bounds = provinceLayer.getBounds();
    else if (['regency', 'city'].includes(props.scope.level)) bounds = featureBounds(regencyData, props.scope.name);
    else if (props.scope.level === 'district') {
        await ensureDistrictLayer();
        if (districtData) bounds = featureBounds(districtData, props.scope.name);
    } else if (props.scope.level === 'village') {
        await ensureVillageLayer();
        if (villageData) bounds = featureBounds(villageData, props.scope.name);
    }
    if (!bounds?.isValid()) bounds = provinceLayer.getBounds();
    map.fitBounds(bounds, { padding: [24, 24], maxZoom: props.scope.level === 'village' ? 13 : props.scope.level === 'district' ? 10 : 8 });
}

function zoomIn(): void { map?.zoomIn(); }
function zoomOut(): void { map?.zoomOut(); }
function resetMap(): void { void fitInitialScope(); }

onMounted(async () => {
    await nextTick();
    if (!mapElement.value) return;
    map = L.map(mapElement.value, { zoomControl: false, attributionControl: true, minZoom: 3, maxZoom: 14, preferCanvas: true, scrollWheelZoom: true });
    map.attributionControl.setPrefix(false);
    map.attributionControl.addAttribution('<a href="https://tanahair.indonesia.go.id/" target="_blank" rel="noopener">Batas BIG</a>');
    basemapLayer = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
        crossOrigin: true,
        referrerPolicy: 'origin',
    });
    basemapLayer.on('load', () => { basemapFailed.value = false; });
    basemapLayer.on('tileerror', () => { basemapFailed.value = true; });
    basemapLayer.addTo(map);
    labelLayer = L.layerGroup().addTo(map);
    createLayers();
    provinceLayer?.addTo(map);
    regencyLayer?.addTo(map);
    await fitInitialScope();
    await setLayerVisibility();
    map.on('zoomend moveend', () => { void setLayerVisibility(); });
    resizeObserver = new ResizeObserver(() => map?.invalidateSize({ pan: false }));
    resizeObserver.observe(mapElement.value);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    map?.off();
    map?.remove();
    map = null;
    basemapLayer = null;
    districtLoad = null;
    villageLoad = null;
});
</script>

<template>
    <div class="relative min-h-[360px] overflow-hidden rounded-xl border border-white/10 bg-slate-950 lg:min-h-[440px]">
        <div ref="mapElement" class="absolute inset-0 z-0" :aria-label="`Peta interaktif ${scope.name}`" role="application" />
        <div class="pointer-events-none absolute left-3 top-3 z-[500] flex max-w-[calc(100%-5.5rem)] flex-wrap gap-2">
            <span class="rounded-lg border border-cyan-400/20 bg-slate-950/90 px-2.5 py-1.5 text-[9px] font-bold text-cyan-200 shadow-xl">{{ activeLayerLabel }}</span>
            <span class="rounded-lg border border-white/10 bg-slate-950/90 px-2.5 py-1.5 text-[9px] text-slate-300 shadow-xl">Zoom {{ zoomLevel }}</span>
        </div>
        <div v-if="basemapFailed" class="pointer-events-none absolute left-3 top-12 z-[500] rounded-lg border border-amber-400/30 bg-amber-950/90 px-3 py-2 text-[9px] font-semibold text-amber-200 shadow-xl">Basemap OSM tidak tersedia · batas wilayah tetap aktif</div>
        <div class="absolute right-3 top-3 z-[500] grid overflow-hidden rounded-lg border border-white/15 bg-slate-950/90 shadow-xl">
            <button type="button" class="h-9 w-9 border-b border-white/10 text-lg font-bold text-white hover:bg-cyan-400/15" aria-label="Perbesar peta" @click.stop="zoomIn">+</button>
            <button type="button" class="h-9 w-9 text-lg font-bold text-white hover:bg-cyan-400/15" aria-label="Perkecil peta" @click.stop="zoomOut">−</button>
        </div>
        <div class="absolute bottom-10 left-3 z-[500] flex flex-wrap gap-2">
            <button type="button" class="rounded-lg border border-white/15 bg-slate-950/90 px-3 py-2 text-[10px] font-semibold text-slate-200 shadow-xl hover:border-cyan-300/40 hover:text-cyan-200" @click.stop="resetMap">Lihat semua</button>
        </div>
    </div>
</template>

<style scoped>
:deep(.leaflet-container) {
    background: radial-gradient(circle at 58% 42%, rgba(14, 165, 233, 0.13), transparent 44%), linear-gradient(145deg, #071426, #08172b 60%, #06101f);
    font-family: inherit;
}
:deep(.leaflet-tile-pane) { filter: brightness(0.82) saturate(0.78) contrast(1.08); }
:deep(.leaflet-control-attribution) {
    max-width: calc(100vw - 3rem);
    border-radius: 6px 0 0 0;
    background: rgba(248, 250, 252, 0.92) !important;
    color: #334155;
    font-size: 8px;
    line-height: 1.35;
}
:global(.sena-map-tooltip) {
    border: 1px solid rgba(103, 232, 249, 0.25) !important;
    background: rgba(2, 6, 23, 0.92) !important;
    color: #e2e8f0 !important;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.28) !important;
    font-size: 11px !important;
    font-weight: 700 !important;
}
:global(.sena-map-tooltip::before) { border-top-color: rgba(2, 6, 23, 0.92) !important; }
:global(.sena-map-label-shell) { width: auto !important; height: auto !important; border: 0 !important; background: transparent !important; }
:global(.sena-map-label) {
    display: inline-block;
    transform: translate(-50%, -50%);
    white-space: nowrap;
    color: #f8fafc;
    font-size: 9px;
    font-weight: 800;
    line-height: 1;
    text-shadow: 0 1px 3px #020617, 0 0 5px #020617;
}
:global(.sena-map-label--supiori) {
    border: 1px solid rgba(103, 232, 249, 0.7);
    border-radius: 999px;
    background: rgba(8, 47, 73, 0.92);
    padding: 4px 7px;
    color: #a5f3fc;
    box-shadow: 0 0 0 3px rgba(34, 211, 238, 0.12);
}
</style>
