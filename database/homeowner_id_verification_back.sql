-- Run once on the InfinityFree database where verification_id_path already exists.
-- The live database is also missing the legacy lot_number field used by registration.
ALTER TABLE homeowners
    ADD COLUMN verification_id_back_path VARCHAR(255) NULL AFTER verification_id_path,
    ADD COLUMN lot_number VARCHAR(20) NULL AFTER lot,
    ADD UNIQUE KEY lot_number (lot_number);
