# Release Process

1. Make sure `main` is green in GitLab: PHPUnit, PHPCS, PHPStan, package, and PCP.
2. Choose the next semantic version, for example `0.2.0`.
3. Run `composer bump-version -- 0.2.0`.
4. Update [`CHANGELOG.md`](../CHANGELOG.md) for that version.
5. Review the changes in `pushpull.php`, `readme.txt`, and `CHANGELOG.md`.
6. Commit the version bump with "Release 0.0.24" .
7. Create and push a Git tag in the form `v0.2.0` on that commit.
8. Let GitLab run the tag pipeline.
9. The package job will build `build/pushpull-0.2.0.zip` from that tagged commit.
10. Download the ZIP artifact from the tag pipeline and upload it in WordPress.
11. Verify after upload that WordPress shows the new plugin version correctly.
