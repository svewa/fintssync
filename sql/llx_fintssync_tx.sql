-- Bank transactions imported through FinTS
CREATE TABLE llx_fintssync_tx (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	tx_hash varchar(40) NOT NULL,
	datetx date NOT NULL,
	amount double(24,8) NOT NULL,
	name varchar(255),
	own_iban varchar(40),						-- our own bank account the transaction belongs to
	source varchar(8) DEFAULT 'bank',				-- bank or paypal
	currency varchar(3) DEFAULT 'EUR',
	fee double(24,8) DEFAULT 0,					-- PayPal fee (negative = cost)
	invoice_ref varchar(128),					-- PayPal invoice number
	txtype varchar(16),						-- PayPal event code
	account varchar(64),						-- counterparty account (IBAN)
	eref varchar(255),
	purpose text,
	status integer DEFAULT 0 NOT NULL,		-- 0=new, 1=booked, 2=review, 3=ignored
	fk_soc integer,
	match_kind varchar(16),					-- full, skonto, manual
	match_json text,						-- proposed allocations: {"invoice_id": amount}
	match_note varchar(255),
	fk_paiement integer,
	datec datetime,
	tms timestamp
) ENGINE=innodb;
