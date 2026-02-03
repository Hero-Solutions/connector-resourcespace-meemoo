#!/bin/bash
cd /opt/connector-resourcespace-meemoo/
date -u >> /opt/connector-resourcespace-meemoo/output/offloadlog.txt 
php /opt/connector-resourcespace-meemoo/bin/console app:process-offloaded-resources -v >> /opt/connector-resourcespace-meemoo/output/offloadlog.txt 2>&1
php /opt/connector-resourcespace-meemoo/bin/console app:offload-resources -v >> /opt/connector-resourcespace-meemoo/output/offloadlog.txt 2>&1
date -u >> /opt/connector-resourcespace-meemoo/output/offloadlog.txt

