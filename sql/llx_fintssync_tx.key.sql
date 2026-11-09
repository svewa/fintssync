ALTER TABLE llx_fintssync_tx ADD UNIQUE INDEX uk_fintssync_tx_hash (entity, tx_hash);
ALTER TABLE llx_fintssync_tx ADD INDEX idx_fintssync_tx_status (status);
ALTER TABLE llx_fintssync_tx ADD INDEX idx_fintssync_tx_soc (fk_soc);
