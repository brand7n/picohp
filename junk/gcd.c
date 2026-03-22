unsigned gcd(unsigned a, unsigned b) {
	if (b == 0)
		return a + b;
	else
		return 0;
}

int main() 
{
    gcd(0,1);
    gcd(1,0);
}
