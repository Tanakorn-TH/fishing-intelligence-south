# Bathymetry import for fishing planning

Use the Department of Mineral Resources (DMR) bathymetry dataset as the primary layer for Southern Thailand. It contains contours for the Gulf of Thailand and Andaman Sea at 1:50,000, published as CSV, Shapefile and ArcGIS REST resources under CC BY. Record the exact download URL, retrieval date and licence in `data_sources` before importing.

1. Load `data-model.sql` into MySQL 8.0.13 or newer.
2. Reproject the official DMR Shapefile to EPSG:4326 with `ogr2ogr`, preserving its depth attribute. MySQL cannot reproject an arbitrary source CRS during import, so the file must already be in EPSG:4326 before it reaches the database.
3. Insert the contours into `bathymetry_contours`; set the source vertical datum exactly as stated in the data documentation. **Write every coordinate as `POINT(lat lon)` / `LINESTRING(lat lon, …)`** — MySQL orders SRID 4326 latitude first, the opposite of PostGIS, GeoJSON and the Shapefile itself. `ogr2ogr` emits longitude first, so the axes must be swapped during the load.
4. For every public fishing spot, calculate the depth range inside a declared radius, review it, then store the result in `spot_depth_profiles`. Filter with `MBRIntersects` before `ST_Distance_Sphere`, or the query will not use the spatial index; there is a worked example at the end of `data-model.sql`.
5. Create `trip_plans` from the calendar score. Query `gear_rules` against `typical_depth_m` to build the packing checklist in `trip_gear_items`.

`bathymetry_contours` cannot carry a unique constraint on the geometry column itself, because MySQL will not index geometry for uniqueness. The table stores a `geom_sha256` generated column instead, and the unique key covers `(source_id, depth_m, geom_sha256)`. Re-running an import is therefore safe: identical shapes from the same source are rejected rather than duplicated.

Never infer a nearshore depth from a broad global grid. GEBCO 2026 is suitable only as a supplementary regional layer, has mixed source quality, and explicitly must not be used for navigation or sea-safety decisions. Keep its source identifier and any TID/source-quality information if it is used.

The current website is a front-end prototype. The calendar scores are example values until tide, weather, solunar and the approved depth layer are ingested by a backend job.
