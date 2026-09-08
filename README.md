# JuControlConnector

Folgende Module beinhaltet das JuControlConnector Repository:

- __JuControlDevice__ ([Dokumentation](JuControlDevice))  
	Anbindung einer Judo i-soft safe Wasserenthärtungsanlage. Aktuell werden folgende Gerätetypen unterstützt:
  * Judo i-soft SAFE+
  * Judo i-soft plus

## Tests

Der Regressionstest läuft ohne Symcon gegen den offiziellen Kernel-Stub
[symcon/SymconStubs](https://github.com/symcon/SymconStubs), der als Git-Submodul unter
`tests/stubs` eingebunden und auf einen festen Commit gepinnt ist:

```
git submodule update --init
php tests/check-incomplete-devicedata.php
```

Exit-Code 1 bei Fehlern; die CI (`.github/workflows/check.yml`) führt denselben Aufruf aus.
Ein neuerer Stub-Stand ist ein bewusster Commit (`git -C tests/stubs checkout <hash>`, dann
`git add tests/stubs`), nie `git submodule update --remote`.
