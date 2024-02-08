A set of custom DDEV commands I use regularly.

I want to use [stow](https://www.gnu.org/software/stow/) to manage these, so I can store just my custom commands in the git repo and they get symlinked to DDEV.

```bash
stow --target=$HOME/.ddev/commands .
```

> [!WARNING]
> Unfortunately that [isn't currently supported](https://github.com/ddev/ddev/issues/5806), so for now I have to use hard links. I've written a script to handle that for me.
>
> The script first deletes all old hard links in `$HOME/.ddev/commands`, then creates new hard links from any files not in the `.links-ignore` list.
>
> ```bash
> ./make-links.sh
> ```
