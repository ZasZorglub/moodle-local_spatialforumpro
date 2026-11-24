<?php
// This configuration is used only inside the isolated disposable QA container.

if (__DIR__ !== '/tmp/sfpro-moodle') {
    exit(1);
}

unset($CFG);
global $CFG;
$CFG = new stdClass();
$CFG->dbtype = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost = 'db';
$CFG->dbname = 'sfpro';
$CFG->dbuser = 'sfpro';
$CFG->dbpass = 'qa-disposable-password';
$CFG->prefix = 'mdl_';
$CFG->dboptions = ['dbport' => '', 'dbsocket' => '', 'dbpersist' => false];
$CFG->wwwroot = getenv('SFPRO_WWWROOT') ?: 'http://127.0.0.1:8081';
$CFG->slasharguments = false;
$CFG->dataroot = '/tmp/sfpro-data';
$CFG->directorypermissions = 02777;
$CFG->phpunit_prefix = 'tst_';
$CFG->phpunit_dataroot = '/tmp/sfpro-phpunit';
$CFG->noemailever = true;

require_once(__DIR__ . '/lib/setup.php');
