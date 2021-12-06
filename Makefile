#List of available tasks

list:
	vendor/bin/phing -l

#Perform syntax check and mess detection of sourcecode files, find duplicate code
start:
	    vendor/bin/phing start

#Code style fixes - find coding standard violations and print human readable output
fix:
	vendor/bin/phing fix

dryrun:
	vendor/bin/phing phpcsfixer-dry-run

concat:
	gulp concat

purge:
	gulp purge

phing:
	   echo vendor/bin/phing -Dmodule=path/custom/module