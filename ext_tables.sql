CREATE TABLE sys_category (
    tx_fmp_icon varchar(32) DEFAULT 'folder' NOT NULL,
    tx_fmp_color varchar(7) DEFAULT '' NOT NULL
);
CREATE TABLE tx_formmanagerplus_profile (
    uid int unsigned NOT NULL auto_increment,
    form_key varchar(64) DEFAULT '' NOT NULL,
    form_identifier varchar(1024) DEFAULT '' NOT NULL,
    purpose varchar(255) DEFAULT '' NOT NULL,
    responsible varchar(255) DEFAULT '' NOT NULL,
    responsible_user int unsigned DEFAULT 0 NOT NULL,
    notes text,
    categories_json text,
    revision int unsigned DEFAULT 0 NOT NULL,
    updated_at int unsigned DEFAULT 0 NOT NULL,
    updated_by int unsigned DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    UNIQUE KEY form_key (form_key)
);
CREATE TABLE tx_formmanagerplus_personal (
    uid int unsigned NOT NULL auto_increment,
    be_user int unsigned DEFAULT 0 NOT NULL,
    workspace int DEFAULT 0 NOT NULL,
    form_key varchar(64) DEFAULT '' NOT NULL,
    favorite smallint unsigned DEFAULT 0 NOT NULL,
    last_edited int unsigned DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    UNIQUE KEY personal_form (be_user,workspace,form_key)
);
CREATE TABLE tx_formmanagerplus_view (
    uid int unsigned NOT NULL auto_increment,
    be_user int unsigned DEFAULT 0 NOT NULL,
    be_group int unsigned DEFAULT 0 NOT NULL,
    workspace int DEFAULT 0 NOT NULL,
    name varchar(80) DEFAULT '' NOT NULL,
    name_key varchar(64) DEFAULT '' NOT NULL,
    state_json text,
    updated_at int unsigned DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    UNIQUE KEY personal_view (be_user,workspace,name_key)
);
CREATE TABLE tx_formmanagerplus_history (
    uid int unsigned NOT NULL auto_increment,
    form_key varchar(64) DEFAULT '' NOT NULL,
    workspace int DEFAULT 0 NOT NULL,
    actor int unsigned DEFAULT 0 NOT NULL,
    actor_label varchar(255) DEFAULT '' NOT NULL,
    created_at int unsigned DEFAULT 0 NOT NULL,
    changes_json mediumtext,
    PRIMARY KEY (uid),
    KEY form_history (form_key,workspace,uid)
);
