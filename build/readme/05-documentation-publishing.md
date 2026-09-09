# Publishing the documentation

The pages under `docs/` are used twice. They are compiled into every binary (`boson.json` lists
`docs/` in `build.directories`) and shown by the in-app **Help** screen, and they are the public
documentation served in the Documentation section of [Grafida.app](https://grafida.app).
`scripts/upload-docs.sh` mirrors the second from the first over SFTP.

**Publishing the documentation is not a release step.** An installed copy carries whatever pages it
shipped with, so republishing the site changes nothing about a build and needs no build to have
happened. A typo, a corrected instruction, or a new screenshot is worth publishing the day it is
committed rather than waiting for the next version. Run the script whenever the pages change.

```sh
composer docs:publish -- --dry-run    # list what would be sent, connect to nothing
composer docs:publish                 # publish
```

`scripts/upload-docs.sh` is the same thing without Composer in the way, and takes the flags
directly.

## Configuration

The script reads two required settings and two optional ones from `build/build.properties` — the
gitignored private build configuration, copied from `build/build.sample.properties` — or from the
process environment, which wins.

| Property | Environment | | Meaning |
| --- | --- | --- | --- |
| `docs.sftp.host` | `DOCS_SFTP_HOST` | required | The `~/.ssh/config` `Host` alias to connect to. |
| `docs.sftp.path` | `DOCS_SFTP_PATH` | required | The target directory, absolute or relative to the login directory. It must already exist. |
| `docs.sftp.user` | `DOCS_SFTP_USER` | see below | The account to connect as, when `~/.ssh/config` does not set one for the host. |
| `docs.sftp.port` | `DOCS_SFTP_PORT` | optional | Overrides the `Port` set for that host, which itself defaults to 22. |

```properties
docs.sftp.host=grafida-docs
docs.sftp.path=/home/username/public_html/media/com_docs/books/desktop
```

**The account has to come from somewhere.** Either the host's `~/.ssh/config` entry sets `User` or
`docs.sftp.user` does. With neither, ssh connects as your local login name, which on shared hosting
is never the right account, and the resulting `Permission denied` does not say why. The script
resolves the account with `ssh -G` before it connects, prints it on the destination line, and warns
when it turned out to be your local login name:

```
Publishing 25 page(s), 22 image(s) and _manifest.json
        to grafida-docs@sftp.example.com:/home/username/public_html/media/com_docs/books/desktop
```

Run the script with `--dry-run` to see that line, and the whole SFTP batch, without connecting to
anything.

## Authentication

How the connection authenticates is `~/.ssh/config`'s business, not the script's. The entry for the
host alias supplies the identity, the user, the port, and anything else the connection needs:

```
Host grafida-docs
    HostName sftp.example.com
    User username
    IdentityAgent "~/Library/Group Containers/2BUA8C4S2C.com.1password/t/agent.sock"
```

The `IdentityAgent` line points ssh at 1Password's SSH agent, so the key never exists as a file and
every connection is authorised by a biometric prompt the agent raises itself. Answer it when it
appears; the upload proceeds once it is approved.

A real hostname in `docs.sftp.host` works just as well, and picks up whatever a `Host *` block sets
for every connection, `IdentityAgent` included. An alias is only tidier: it keeps the user, the port
and the identity in one place, and leaves the build configuration naming a destination rather than
describing one. A plain hostname with no entry of its own inherits no `User`, though, so
`docs.sftp.user` has to supply it.

⚠️ **There is deliberately no key setting and no password setting, and neither should be added.** A
path to a key file would route around the agent, and the site's credentials do not belong in a file
this repository can see — `build/build.properties` is gitignored, but it already holds the GitHub
token and the CDN password, and that is as far as it should go.

### What batch mode does and does not suppress

The script drives `sftp -b`, which implies `BatchMode=yes`. That stops **ssh** from prompting, and
nothing else. The 1Password prompt is the agent's own window, out of ssh's hands, so it still
appears and still has to be answered.

What does fail outright under batch mode is a host that wants a password, and a host whose key is
not yet in `known_hosts` — ssh will not ask you to accept it. Connect once by hand to get the host
key recorded:

```sh
sftp grafida-docs
```

## What is uploaded

Everything the documentation set consists of, and nothing else in the directory:

- every `docs/*.md` page;
- every image in `docs/images/` (`.png`, `.jpg`, `.jpeg`, `.gif`, `.svg`, `.webp`), into an
  `images/` directory beside the pages, created if it is not already there;
- `_manifest.json`, the table of contents.

`docs/images/` is the only subdirectory the documentation set has, and it is itself flat. Nothing
else is recursed into and no other directory is created.

`_manifest.json` is uploaded last, after every page it points at. A reader who loads the site in the
middle of an upload therefore sees the old table of contents rather than a new entry linking to a
page the server does not have yet.

## Before it connects

Two checks run against the local files first, and both are cheap enough to be worth the second they
cost:

- Every slug named in `_manifest.json` must have a matching `.md` file. A missing one is fatal;
  publishing a table of contents that links to nothing is the one failure every reader sees at once.
- A `.md` file that no manifest entry names produces a warning. It is still uploaded, since an
  unlinked page reachable by URL is harmless, but it is almost always a manifest edit that was
  forgotten.

`composer test` pins the same agreement from the other side, through
`HelpRoutingTest::testEveryAdvertisedPageRenders()`. The script repeats it because the site can be
published from a working tree nobody has just run the suite against.

## Nothing is ever deleted

The script only uploads. A page that was renamed or dropped from the documentation set keeps its old
file on the server, still served at its old URL, indefinitely.

Delete it by hand in the same session that renames or removes it. Nothing else will notice: the
in-app Help reads the binary's own copy, not the site, so the app is correct while the site quietly
serves a page that no longer exists.
