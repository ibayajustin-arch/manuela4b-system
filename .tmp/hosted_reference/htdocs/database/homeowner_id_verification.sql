-- Run once per Manuela database to support private resident ID uploads.
ALTER TABLE homeowners
    ADD COLUMN verification_id_path VARCHAR(255) NULL AFTER status;
