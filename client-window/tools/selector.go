//go:build windows

package tools

import (
	"image"
)

func SelectRegion() (rect image.Rectangle, ok bool, err error) {
	return selectRegionWindows()
}
