#include <stdint.h>
#include <stdio.h>

float a = 27;

int32_t test1(int32_t c)
{
    return a + c;
}

int32_t main()
{
    volatile int32_t t = 3;
    int32_t a = 5 + 4 * t;
    {
        int32_t b = a;
        printf("a: %d b: %d test1: %d\n", a, b, test1(a));
    }
    return a;
}

