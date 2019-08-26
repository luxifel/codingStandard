#List of available tasks

list:
	vendor/bin/phing -l

#Perform syntax check and mess detection of sourcecode files, find duplicate code
build:
	    vendor/bin/phing build

#Code style fixes - find coding standard violations and print human readable output
style:
	vendor/bin/phing style

concat:
	gulp concat

purge:
	gulp purge



phing:
	   echo vendor/bin/phing -Dmodule=path/custom/module