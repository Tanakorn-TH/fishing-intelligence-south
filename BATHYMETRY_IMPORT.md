# Bathymetry import for fishing planning

Use the Department of Mineral Resources (DMR) bathymetry dataset as the primary layer for Southern Thailand. It contains contours for the Gulf of Thailand and Andaman Sea at 1:50,000, published as CSV, Shapefile and ArcGIS REST resources under CC BY. Record the exact download URL, retrieval date and licence in `data_sources` before importing.

1. Load `data-model.sql` into PostgreSQL with PostGIS.
2. Import the official DMR Shapefile to a staging table with `ogr2ogr`, preserving its original coordinate reference system and depth attribute.
3. Transform its geometry to EPSG:4326 and insert it into `bathymetry_contours`; set the source vertical datum exactly as stated in the data documentation.
4. For every public fishing spot, calculate the depth range inside a declared radius, review it, then store the result in `spot_depth_profiles`.
5. Create `trip_plans` from the calendar score. Query `gear_rules` against `typical_depth_m` to build the packing checklist in `trip_gear_items`.

Never infer a nearshore depth from a broad global grid. GEBCO 2026 is suitable only as a supplementary regional layer, has mixed source quality, and explicitly must not be used for navigation or sea-safety decisions. Keep its source identifier and any TID/source-quality information if it is used.

The current website is a front-end prototype. The calendar scores are example values until tide, weather, solunar and the approved depth layer are ingested by a backend job.
