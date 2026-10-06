ALTER TABLE shipment_events ADD COLUMN title VARCHAR(120) DEFAULT '';
ALTER TABLE shipment_events ADD COLUMN badge VARCHAR(40) DEFAULT 'Departed';
ALTER TABLE shipment_events ADD COLUMN icon VARCHAR(20) DEFAULT 'truck';
