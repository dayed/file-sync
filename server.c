#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <fcntl.h>
#include <resolv.h>
#include <sys/types.h>
#include <sys/socket.h>
#include <errno.h>
#include <err.h>

#include "sock.c"

int
make_socket (uint16_t port)
{
  int sock;
  struct sockaddr_in name;

  /* Create the socket. */
  sock = socket (PF_INET, SOCK_STREAM, 0);
  if (sock < 0)
    {
      perror ("socket");
      exit (EXIT_FAILURE);
    }

  /* Give the socket a name. */
  name.sin_family = AF_INET;
  name.sin_port = htons (port);
  name.sin_addr.s_addr = htonl (0);
  if (bind (sock, (struct sockaddr *) &name, sizeof (name)) < 0)
    {
      perror ("bind");
      exit (EXIT_FAILURE);
    }

  return sock;
}
int mkdir_recur(char * filename)
{
	struct stat st;
	char *p = filename+1;
	char dir[FILENAME_MAX];
	for (; *p; p++)
	{
		if (*p == '/')
		{
			int len = p - filename;
			strncpy(dir, filename, len);
			dir[len] = '\0';
			if (lstat(dir, &st) == -1) {
				printf("mkdir %s\n", dir);
				int ret;
				if (ret = mkdir(dir, 0777)) {
					return ret;
				}
			}
		}
	}
	return 0;
}
int main(int argc, char const *argv[])
{
	printf("%s\n", "start");
	int sock;
	sock = make_socket(8081);
	if (listen(sock, 5) < 0)
	{
		perror("listen");
		exit(EXIT_FAILURE);
	}
	struct sockaddr_in name;
	int new_fd;
	int addr_size = sizeof(struct sockaddr_in);
	while (1) {
		new_fd = accept(sock, (struct sockaddr*)&name, &addr_size);
		if (new_fd < 0)
		{
			perror ("accept");
			exit (EXIT_FAILURE);
		}

		char dest[FILENAME_MAX];
		recv_str(new_fd, dest, FILENAME_MAX);
		printf("dest: %s\n", dest);
		struct stat st;
		if (lstat(dest, &st) == -1) {
			send_int(new_fd, 0);
			if (mkdir_recur(dest)) {
				perror("mkdir_recur");
				close(new_fd);
				close(sock);
				exit(EXIT_FAILURE);
			}
		} else {
			send_int(new_fd, st.st_size);
		}
		int size = recv_int(new_fd);
		printf("%d bytes follow\n", size);
		if (size > 0)
		{
			if (recv_file(sock, dest, size) < 0) {
				perror("recv_file");
				close(new_fd);
				close(sock);
				exit(EXIT_FAILURE);
			}
		}
		close(new_fd);
		break;
	}
	close(sock);
	printf("%s\n", "end");
	return 0;
}
