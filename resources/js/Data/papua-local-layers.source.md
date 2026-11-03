# Interactive Papua map layers

The interactive map keeps simplified GeoJSON copies locally so routine use does not depend on an internet connection.

## District layer

- Authority: Badan Informasi Geospasial (BIG)
- Service: `BATASWILAYAH/BATAS_KECAMATAN_AR`
- Layer: `Area Batas Wilayah Administrasi Kecamatan`
- URL: `https://geoservices.big.go.id/rbi/rest/services/BATASWILAYAH/BATAS_KECAMATAN_AR/MapServer/0`
- Filter: `WADMPR = 'Papua'`
- Local copy: `papua-districts.json`
- Features: 108 source polygons, including BIG's undefined-area records; undefined areas are excluded from the rendered district layer.

## Village/urban-village layer

- Authority: Badan Informasi Geospasial (BIG)
- Service: `BATASWILAYAH/BATAS_DESAKEL_AR`
- Layer: `Area Batas Wilayah Administrasi Desa/Kelurahan`
- URL: `https://geoservices.big.go.id/rbi/rest/services/BATASWILAYAH/BATAS_DESAKEL_AR/MapServer/0`
- Filter: `WADMPR = 'Papua'`
- Local copy: `papua-villages.json`
- Features: 1,040 polygons.

Both layers were retrieved on 30 October 2026 in WGS84 GeoJSON, cleaned, simplified with shape preservation, and exported at coordinate precision `0.0001` for browser rendering.

These layers provide internal geographic visualization only. Election scopes and result filtering continue to use region records explicitly imported into the application master. The visualization is not a legal boundary determination.
