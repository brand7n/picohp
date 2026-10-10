.text
.global _start

_start:
    movz x0, #42
    movz x1, #10
    add  x0, x0, x1

loop:
    sub  x0, x0, x1
    b    loop
