document.addEventListener("DOMContentLoaded", () => {
  const viewerElement = document.querySelector("#viewer");
  const markdownField = document.querySelector("#post-markdown");

  if (viewerElement && markdownField) {
    if (window.toastui?.Editor) {
      new window.toastui.Editor({
        el: viewerElement,
        initialValue: markdownField.value,
        usageStatistics: false,
      });
    } else {
      viewerElement.textContent = markdownField.value;
      viewerElement.style.whiteSpace = "pre-wrap";

      console.error("기존 Markdown Viewer를 불러오지 못했습니다.");
    }
  }

  async function readJsonResponse(response) {
    const contentType = response.headers.get("content-type") || "";

    if (response.redirected || !contentType.includes("application/json")) {
      throw new Error(
        "서버가 JSON 대신 페이지를 반환했습니다. " +
          "로그인 상태와 PHP 저장 처리를 확인해주세요. " +
          "중복 등록을 피하려면 목록을 먼저 확인해주세요.",
      );
    }

    return response.json();
  }

  // write.php
  const form = document.querySelector("#write-form");

  if (form) {
    const fileInput = form.querySelector("#file_upload");
    const fileDropzone = form.querySelector("[data-file-dropzone]");
    const fileDropTarget = form.querySelector(".file_dropzone");
    const fileList = form.querySelector("[data-file-list]");

    form.querySelectorAll("[data-delete-attachment]").forEach((button) => {
      button.addEventListener("click", () => {
        const item = button.closest("[data-existing-attachment]");
        const attachmentId = item?.dataset.attachmentId;

        if (!item || !attachmentId) {
          return;
        }

        const deletedInput = document.createElement("input");
        deletedInput.type = "hidden";
        deletedInput.name = "deleted_attachment_ids[]";
        deletedInput.value = attachmentId;
        form.append(deletedInput);
        item.remove();
      });
    });

    function renderFileList(files) {
      if (!fileList) {
        return;
      }

      fileList.replaceChildren();

      for (const [index, file] of Array.from(files || []).entries()) {
        const itemBox = document.createElement("li");
        const item = document.createElement("span");
        const deleteButton = document.createElement("button");

        item.textContent = `${file.name} (${Math.ceil(file.size / 1024)} KB)`;
        deleteButton.type = "button";
        deleteButton.textContent = "삭제";
        deleteButton.setAttribute("aria-label", `${file.name} 삭제`);

        deleteButton.addEventListener("click", () => {
          if (!fileInput) {
            return;
          }

          const transfer = new DataTransfer();

          for (const [fileIndex, attachedFile] of Array.from(
            fileInput.files || [],
          ).entries()) {
            if (fileIndex !== index) {
              transfer.items.add(attachedFile);
            }
          }

          fileInput.files = transfer.files;
          renderFileList(fileInput.files);
        });

        itemBox.append(item, deleteButton);
        fileList.append(itemBox);
      }
    }

    function setAttachedFiles(files) {
      if (!fileInput) {
        return;
      }

      const transfer = new DataTransfer();

      for (const file of Array.from(fileInput.files || [])) {
        transfer.items.add(file);
      }

      for (const file of Array.from(files || [])) {
        const alreadyAttached = Array.from(transfer.files).some(
          (attachedFile) =>
            attachedFile.name === file.name &&
            attachedFile.size === file.size &&
            attachedFile.lastModified === file.lastModified,
        );

        if (!alreadyAttached) {
          transfer.items.add(file);
        }
      }

      fileInput.files = transfer.files;
      renderFileList(fileInput.files);
    }

    if (fileInput && fileDropzone && fileDropTarget) {
      fileInput.addEventListener("change", () => {
        const selectedFiles = Array.from(fileInput.files || []);
        setAttachedFiles(selectedFiles);
      });

      fileDropTarget.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          fileInput.click();
        }
      });

      for (const eventName of ["dragenter", "dragover"]) {
        fileDropzone.addEventListener(eventName, (event) => {
          event.preventDefault();
          fileDropTarget.classList.add("is-dragging");
        });
      }

      for (const eventName of ["dragleave", "drop"]) {
        fileDropzone.addEventListener(eventName, (event) => {
          event.preventDefault();
          fileDropTarget.classList.remove("is-dragging");
        });
      }

      fileDropzone.addEventListener("drop", (event) => {
        setAttachedFiles(event.dataTransfer.files);
      });
    }

    const editorElement = form.querySelector("#editor");
    const contentField = form.querySelector('[name="content"]');
    const errorElement = form.querySelector("#content-error");

    const isEdit = form.hasAttribute("data-edit-form");

    const contentFormat = form.dataset.contentFormat || "markdown";
    const isLegacyEdit = isEdit && contentFormat !== "html";

    const localImages = new Map();

    let $editor = null;
    let isSaving = false;

    const MAX_IMAGE_COUNT = 10;
    const MAX_IMAGE_SIZE = 5 * 1024 * 1024;

    function showError(message) {
      if (errorElement) {
        errorElement.textContent = message;
        errorElement.hidden = false;
      } else {
        alert(message);
      }
    }

    function clearError() {
      if (errorElement) {
        errorElement.hidden = true;
      }
    }

    function getImageSources(html) {
      // HTML을 화면에 출력하지 않고 이미지 주소만 확인
      const template = document.createElement("template");
      template.innerHTML = html;

      return new Set(
        Array.from(template.content.querySelectorAll("img"))
          .map((image) => image.getAttribute("src"))
          .filter(Boolean),
      );
    }

    function addLocalImages(files) {
      if (isSaving || !$editor) {
        return;
      }

      const sources = getImageSources($editor.summernote("code"));

      let activeCount = Array.from(localImages.keys()).filter((url) =>
        sources.has(url),
      ).length;

      const errors = [];

      for (const file of Array.from(files || [])) {
        if (!["image/jpeg", "image/png"].includes(file.type)) {
          errors.push(`${file.name || "이미지"}: JPEG와 PNG만 가능합니다.`);
          continue;
        }

        if (file.size > MAX_IMAGE_SIZE) {
          errors.push(`${file.name || "이미지"}: 5MiB를 초과했습니다.`);
          continue;
        }

        if (activeCount >= MAX_IMAGE_COUNT) {
          errors.push("본문에는 이미지를 최대 10장까지 첨부할 수 있습니다.");
          break;
        }

        const previewUrl = URL.createObjectURL(file);

        const image = document.createElement("img");
        image.src = previewUrl;
        image.alt = "첨부 이미지";
        image.style.maxWidth = "100%";

        localImages.set(previewUrl, file);

        try {
          $editor.summernote("insertNode", image);
          activeCount += 1;
        } catch (error) {
          localImages.delete(previewUrl);
          URL.revokeObjectURL(previewUrl);

          console.error(error);
          errors.push("이미지 미리보기를 삽입하지 못했습니다.");
        }
      }

      if (errors.length > 0) {
        alert(errors.join("\n"));
      }
    }

    if (editorElement && contentField && window.jQuery?.fn?.summernote) {
      $editor = window.jQuery(editorElement);

      $editor.summernote({
        lang: "ko-KR",
        height: 500,
        placeholder: "내용을 입력하세요.",

        toolbar: [
          ["style", ["style"]],
          ["font", ["bold", "italic", "underline", "clear"]],
          ["para", ["ul", "ol", "paragraph"]],
          ["table", ["table"]],
          ["insert", ["link", "picture"]],
          ["history", ["undo", "redo"]],
        ],

        callbacks: {
          onImageUpload: function (files) {
            addLocalImages(files);
          },
        },
      });

      if (isLegacyEdit) {
        const preview = document.createElement("div");
        preview.textContent = contentField.value;
        preview.style.whiteSpace = "pre-wrap";

        $editor.summernote("code", preview.outerHTML);
        $editor.summernote("disable");

        showError(
          "기존 Markdown 게시글입니다. HTML 전환 처리가 준비될 때까지 수정은 잠시 제한됩니다.",
        );
      } else {
        if (!window.DOMPurify) {
          $editor.summernote("disable");
          $editor = null;

          showError("HTML 정제 라이브러리를 불러오지 못했습니다.");
        } else {
          const cleanHtml = DOMPurify.sanitize(contentField.value || "", {
            USE_PROFILES: { html: true },
            FORBID_TAGS: ["style"],
            FORBID_ATTR: ["style"],
          });

          $editor.summernote("code", cleanHtml);
        }
      }
    }

    form.addEventListener("submit", async (event) => {
      event.preventDefault();

      if (isSaving) {
        return;
      }

      if (!$editor) {
        alert("Summernote를 불러오지 못했습니다.");
        return;
      }

      clearError();

      const html = $editor.summernote("code");
      const imageSources = getImageSources(html);

      if ($editor.summernote("isEmpty") && imageSources.size === 0) {
        $editor.summernote("focus");
        return;
      }

      // 새로고침 등으로 파일을 잃은 임시 URL은 저장X
      for (const src of imageSources) {
        if (src.startsWith("blob:") && !localImages.has(src)) {
          showError(
            "파일을 찾을 수 없는 임시 이미지가 있습니다. 삭제 후 다시 첨부해주세요.",
          );
          return;
        }

        if (src.startsWith("data:")) {
          showError(
            "Base64 이미지는 저장하지 않습니다. 이미지 파일로 다시 첨부해주세요.",
          );
          return;
        }
      }

      contentField.value = html;

      const csrfToken = form.querySelector('[name="csrf_token"]')?.value;

      if (!csrfToken) {
        alert("보안 토큰이 없습니다. 페이지를 새로고침해주세요.");
        return;
      }

      const submitButton = form.querySelector('[type="submit"]');

      isSaving = true;

      if (submitButton) {
        submitButton.disabled = true;
      }

      $editor.summernote("disable");

      try {
        let response;
        let destination = "/list";

        if (isEdit) {
          const id = form.querySelector('[name="id"]')?.value;

          if (!id) {
            throw new Error("수정할 게시글 ID가 없습니다.");
          }

          // 수정 요청
          const formData = new FormData(form);
          formData.set("id", id);
          formData.set("content", html);
          formData.set("content_format", "html");
          formData.delete("image_preview_urls");
          formData.delete("images[]");

          let index = 0;

          for (const [previewUrl, file] of localImages) {
            if (!imageSources.has(previewUrl)) {
              continue;
            }

            const extension = file.type === "image/png" ? "png" : "jpg";

            formData.append(
              `images[${index}]`,
              file,
              file.name || `image-${index}.${extension}`,
            );
            formData.append(`image_preview_urls[${index}]`, previewUrl);
            index += 1;
          }

          response = await fetch(form.action, {
            method: "POST",
            headers: {
              Accept: "application/json",
              "X-Requested-With": "XMLHttpRequest",
              "X-CSRF-Token": csrfToken,
            },
            body: formData,
          });

          destination = `/post/${encodeURIComponent(id)}`;
        } else {
          /*
           * 신규 작성 시에만 파일과 본문을 함께 전송
           */
          const formData = new FormData(form);

          formData.delete("files");
          formData.set("content", html);
          formData.set("content_format", "html");

          formData.delete("thumbnail_image_key");
          formData.delete("image");

          let index = 0;

          for (const [previewUrl, file] of localImages) {
            // 본문에서 삭제된 이미지는 업로드X
            if (!imageSources.has(previewUrl)) {
              continue;
            }

            const extension = file.type === "image/png" ? "png" : "jpg";

            formData.append(
              `images[${index}]`,
              file,
              file.name || `image-${index}.${extension}`,
            );

            formData.append(`image_preview_urls[${index}]`, previewUrl);

            index += 1;
          }

          response = await fetch(form.action, {
            method: "POST",
            headers: {
              Accept: "application/json",
              "X-Requested-With": "XMLHttpRequest",
              "X-CSRF-Token": csrfToken,
            },
            body: formData,
          });
        }

        const result = await readJsonResponse(response);

        if (!response.ok || !result.success) {
          throw new Error(
            result.message ||
              (isEdit
                ? "게시글을 수정하지 못했습니다."
                : "게시글을 등록하지 못했습니다."),
          );
        }

        window.location.assign(destination);
      } catch (error) {
        console.error("게시글 저장 실패:", error);

        alert(
          error instanceof Error
            ? error.message
            : "게시글 저장 중 오류가 발생했습니다.",
        );
      } finally {
        isSaving = false;

        if (submitButton) {
          submitButton.disabled = false;
        }

        $editor.summernote("enable");
      }
    });
  }

  // 게시글 삭제
  const deleteButton = document.querySelector("[data-delete-id]");

  if (deleteButton) {
    let isDeleting = false;

    deleteButton.addEventListener("click", async (event) => {
      event.preventDefault();

      if (isDeleting) {
        return;
      }

      const postId = deleteButton.dataset.deleteId;
      const csrfToken = deleteButton.dataset.csrfToken;

      if (!postId || !csrfToken) {
        alert("삭제에 필요한 정보가 없습니다. 새로고침해주세요.");
        return;
      }

      if (!confirm("게시글을 삭제하시겠습니까?")) {
        return;
      }

      isDeleting = true;
      deleteButton.disabled = true;

      try {
        const response = await fetch(`/post/${encodeURIComponent(postId)}`, {
          method: "DELETE",
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-Token": csrfToken,
          },
        });

        const result = await readJsonResponse(response);

        if (!response.ok || !result.success) {
          throw new Error(result.message || "게시글을 삭제하지 못했습니다.");
        }

        window.location.assign("/list");
      } catch (error) {
        console.error("게시글 삭제 실패:", error);

        alert(
          error instanceof Error
            ? error.message
            : "삭제 중 오류가 발생했습니다.",
        );
      } finally {
        isDeleting = false;
        deleteButton.disabled = false;
      }
    });
  }
});
