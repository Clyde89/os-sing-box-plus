.PHONY: package clean

package:
	$(MAKE) -C src/os-sing-box package

clean:
	$(MAKE) -C src/os-sing-box clean
